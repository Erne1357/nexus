"""Las corridas del servicio: viven solo en memoria.

Un reinicio las pierde; el registro duradero es `solver_runs` en PostgreSQL y PHP marca `failed` por tiempo de espera
lo que estaba en curso (docs/arquitectura/solver.md, «Despliegue del servicio Python»). Una corrida ocupa un lugar
desde que se encola hasta que su hilo de trabajo termina, aunque se haya cancelado antes: `runs_in_progress` es la
carga real del servicio.
"""

import logging
import threading
import time
import uuid
from collections import OrderedDict
from collections.abc import Callable
from concurrent.futures import ThreadPoolExecutor
from dataclasses import dataclass, field
from enum import StrEnum
from typing import Any

from .config import Settings
from .contract import SolverInput, to_dict
from .engine import Engine, RunCancelled, RunContext
from .logging import LOGGER_NAME

log = logging.getLogger(LOGGER_NAME)


class RunState(StrEnum):
    QUEUED = "queued"
    RUNNING = "running"
    DONE = "done"
    FAILED = "failed"
    CANCELLED = "cancelled"


class ServiceBusy(Exception):
    """Ya hay `max_concurrent_runs` corridas en curso."""

    def __init__(self, in_progress: int, limit: int) -> None:
        self.in_progress = in_progress
        self.limit = limit
        super().__init__(f"El servicio ya tiene {in_progress} corridas en curso (límite {limit}).")


@dataclass
class Run:
    id: str
    data: SolverInput
    state: RunState = RunState.QUEUED
    progress: int = 0
    best_score: int | None = None
    output: dict[str, Any] | None = None
    error: str | None = None
    #: Verdadero cuando el hilo de trabajo ya terminó; hasta entonces la corrida ocupa un lugar.
    worker_done: bool = False
    cancel: threading.Event = field(default_factory=threading.Event)
    created_at: float = field(default_factory=time.monotonic)

    @property
    def terminal(self) -> bool:
        return self.state in (RunState.DONE, RunState.FAILED, RunState.CANCELLED)


@dataclass(frozen=True)
class RunView:
    """Lo que la API muestra de una corrida, copiado bajo el candado."""

    id: str
    state: RunState
    progress: int
    best_score: int | None
    output: dict[str, Any] | None
    error: str | None


class RunManager:
    def __init__(self, settings: Settings, engine_factory: Callable[[], Engine]) -> None:
        self._settings = settings
        self._engine_factory = engine_factory
        self._runs: OrderedDict[str, Run] = OrderedDict()
        self._lock = threading.Lock()
        self._pool = ThreadPoolExecutor(max_workers=settings.max_concurrent_runs, thread_name_prefix="solver-run")

    def in_progress(self) -> int:
        with self._lock:
            return self._in_progress()

    def _in_progress(self) -> int:
        return sum(1 for run in self._runs.values() if not run.worker_done)

    def submit(self, data: SolverInput) -> Run:
        """Registra la corrida y la manda a un hilo de trabajo; lanza `ServiceBusy` si no hay lugar."""
        with self._lock:
            busy = self._in_progress()
            if busy >= self._settings.max_concurrent_runs:
                raise ServiceBusy(busy, self._settings.max_concurrent_runs)
            run = Run(id=str(uuid.uuid4()), data=data)
            self._runs[run.id] = run
            self._evict()
            self._pool.submit(self._work, run)

        log.info("run_queued", extra=self._facts(run))

        return run

    def get(self, run_id: str) -> RunView | None:
        with self._lock:
            run = self._runs.get(run_id)

            return None if run is None else self._view(run)

    def cancel(self, run_id: str) -> RunView | None:
        """Pide cancelar. Una corrida ya terminada conserva su estado; `None` si el id no existe."""
        with self._lock:
            run = self._runs.get(run_id)
            if run is None:
                return None
            cancelled_now = not run.terminal
            if cancelled_now:
                run.cancel.set()
                run.state = RunState.CANCELLED
            view = self._view(run)

        if cancelled_now:
            log.info("run_cancelled", extra=self._facts(run))

        return view

    @staticmethod
    def _view(run: Run) -> RunView:
        return RunView(run.id, run.state, run.progress, run.best_score, run.output, run.error)

    def shutdown(self) -> None:
        with self._lock:
            for run in self._runs.values():
                run.cancel.set()
        self._pool.shutdown(wait=False, cancel_futures=True)

    def _evict(self) -> None:
        """Descarta las corridas terminadas más viejas cuando se pasa del tope (el lock ya está tomado)."""
        excess = len(self._runs) - self._settings.max_stored_runs
        for run_id in list(self._runs):
            if excess <= 0:
                break
            run = self._runs[run_id]
            if run.terminal and run.worker_done:
                del self._runs[run_id]
                excess -= 1

    def _work(self, run: Run) -> None:
        started = time.perf_counter()
        try:
            with self._lock:
                if run.cancel.is_set():
                    return
                run.state = RunState.RUNNING
            log.info("run_started", extra=self._facts(run))

            engine = self._engine_factory()
            context = RunContext(
                threads=run.data.options.threads or self._settings.threads,
                default_time_limit=self._settings.default_time_limit,
                max_model_variables=self._settings.max_model_variables,
                report=lambda percent, best: self._report(run, percent, best),
                is_cancelled=run.cancel.is_set,
            )
            output = engine.solve(run.data, context)
            payload = to_dict(output)
            with self._lock:
                if not run.cancel.is_set():
                    run.output = payload
                    run.progress = 100
                    run.best_score = output.soft_score.total
                    run.state = RunState.DONE
        except RunCancelled:
            pass
        except Exception as error:
            log.exception("run_error", extra=self._facts(run))
            with self._lock:
                if not run.cancel.is_set():
                    run.error = f"{type(error).__name__}: {error}"
                    run.state = RunState.FAILED
        finally:
            with self._lock:
                run.worker_done = True
            log.info(
                "run_finished", extra={**self._facts(run), "elapsed_seconds": round(time.perf_counter() - started, 3)}
            )

    def _report(self, run: Run, percent: int, best_score: int | None) -> None:
        with self._lock:
            run.progress = max(run.progress, min(100, max(0, percent)))
            if best_score is not None:
                run.best_score = best_score

    def _facts(self, run: Run) -> dict[str, Any]:
        return {
            "run_id": run.id,
            "contract_run_id": run.data.run_id,
            "engine": self._settings.engine,
            "status": run.state.value,
            "groups": len(run.data.groups),
            "scope": f"{run.data.scope.kind.value}:{run.data.scope.id}",
        }
