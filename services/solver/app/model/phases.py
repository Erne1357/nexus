"""El modelo duro de punta a punta, sin objetivo: armar, resolver la fase A y repartir los salones (fase B).

Primero con cada grupo hasta el primer nivel del patrón donde alguna opción cabe; si eso es infactible y algún grupo
dejó niveles sin revisar, una segunda vuelta con todos los niveles. Sin objetivo, CP-SAT se detiene en la primera
solución factible. La resolución con objetivo, avance y relajación es de la tarea 7.7, que parte de aquí.
"""

import os
import threading
import time
from collections.abc import Callable
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

from ortools.sat.python import cp_model

from app.contract.output import ProposedSession

from .constraints import add_hard_constraints
from .data import ModelData
from .rooms import RoomAssignmentError
from .solution import sessions_of
from .variables import BuildBudget, BuildTimeout, DomainOptions, build_variables

FEASIBLE = (cp_model.OPTIMAL, cp_model.FEASIBLE)

#: Cada cuánto se revisa la cancelación mientras CP-SAT resuelve.
CANCEL_POLL_SECONDS = 0.2


def available_cpus() -> int:
    """Los núcleos que el contenedor puede usar: la cuota de cgroup (`cpus` de compose) si la hay; si no, los de la
    máquina. `os.cpu_count()` solo da los de la máquina, aunque el contenedor tenga 2."""
    try:
        quota, period = Path("/sys/fs/cgroup/cpu.max").read_text().split()
        if quota != "max":
            return max(1, int(int(quota) / int(period)))
    except (OSError, ValueError):
        pass

    return os.cpu_count() or 1


@dataclass(frozen=True)
class SearchLimits:
    """Lo que el servicio le pone a una corrida: los hilos de CP-SAT, cuántas variables como mucho y cómo saber si la
    cancelaron (`RunContext.check_cancelled`, que lanza su excepción)."""

    workers: int = 1
    max_variables: int | None = None
    check_cancelled: Callable[[], None] | None = None

    @classmethod
    def of(cls, context: Any) -> "SearchLimits":
        """Los de un `RunContext`: `threads` (0 son los núcleos del contenedor, nunca más),
        `max_model_variables` y `check_cancelled`."""
        return cls(
            workers=context.threads if context.threads > 0 else available_cpus(),
            max_variables=context.max_model_variables,
            check_cancelled=context.check_cancelled,
        )

    def budget(self, deadline: float) -> BuildBudget:
        return BuildBudget(max_variables=self.max_variables, deadline=deadline, check_cancelled=self.check_cancelled)

    def tick(self) -> None:
        if self.check_cancelled is not None:
            self.check_cancelled()


class _OnSolution(cp_model.CpSolverSolutionCallback):
    """Llama a `handler` con cada solución intermedia de CP-SAT (lo usa el avance de la corrida, 7.7)."""

    def __init__(self, handler: Callable[[cp_model.CpSolverSolutionCallback], None]) -> None:
        super().__init__()
        self._handler = handler

    def on_solution_callback(self) -> None:
        self._handler(self)


def run_solver(
    solver: cp_model.CpSolver,
    model: cp_model.CpModel,
    limits: SearchLimits,
    on_solution: Callable[[cp_model.CpSolverSolutionCallback], None] | None = None,
) -> Any:
    """`solver.solve(model)` con los hilos de los límites y revisando la cancelación cada `CANCEL_POLL_SECONDS`: si
    la cancelan, detiene la búsqueda (`stop_search`) y vuelve a lanzar la excepción de la cancelación. Con
    `on_solution`, lo llama en cada solución intermedia (no debe lanzar excepciones: corre en un hilo de CP-SAT)."""
    solver.parameters.num_workers = limits.workers
    callback = _OnSolution(on_solution) if on_solution is not None else None
    if limits.check_cancelled is None:
        return solver.solve(model, callback)

    done = threading.Event()
    raised: list[BaseException] = []

    def watch() -> None:
        while not done.wait(CANCEL_POLL_SECONDS):
            try:
                limits.tick()
            except BaseException as error:
                raised.append(error)
                solver.stop_search()
                return

    watcher = threading.Thread(target=watch, daemon=True)
    watcher.start()
    try:
        status = solver.solve(model, callback)
    finally:
        done.set()
        watcher.join()
    if raised:
        raise raised[0]

    return status


@dataclass
class Round:
    """Una vuelta: el modelo que se armó y lo que tardó."""

    all_tiers: bool
    options: int
    room_variables: int
    variables: int
    constraints: int
    build_seconds: float
    solve_seconds: float
    status: str


@dataclass
class HardRun:
    status: int
    sessions: list[ProposedSession] = field(default_factory=list)
    rounds: list[Round] = field(default_factory=list)
    #: Por qué no hay sesiones aunque la fase A tuvo solución (la fase B no repartió los salones; no debería pasar).
    error: str | None = None

    @property
    def feasible(self) -> bool:
        return self.status in FEASIBLE


def solve_hard(
    data: ModelData,
    time_limit: float,
    limits: SearchLimits | None = None,
    seed: int = 0,
    pool_rooms: bool = True,
    tiers_by_need: bool = True,
) -> HardRun:
    """La primera solución sin violaciones duras, o el estado de CP-SAT si no la hay en el tiempo. Sin
    `pool_rooms` ni `tiers_by_need` es el modelo exacto de 7.4 y 7.5 (un `z` por ventana y salón, todos los niveles),
    que las pruebas usan para comparar."""
    limits = limits or SearchLimits()
    deadline = time.perf_counter() + time_limit
    run = HardRun(status=cp_model.UNKNOWN)

    for all_tiers in (False, True) if tiers_by_need else (True,):
        started = time.perf_counter()
        model = cp_model.CpModel()
        try:
            variables = build_variables(
                model, data, DomainOptions(pool_rooms=pool_rooms, all_tiers=all_tiers), limits.budget(deadline)
            )
        except BuildTimeout:
            run.status = cp_model.UNKNOWN
            return run
        add_hard_constraints(model, variables, data)
        built = time.perf_counter()

        solver = cp_model.CpSolver()
        solver.parameters.max_time_in_seconds = max(0.1, deadline - built)
        solver.parameters.random_seed = seed
        status = run_solver(solver, model, limits)
        run.status = status
        run.rounds.append(
            Round(
                all_tiers=all_tiers,
                options=variables.option_count,
                room_variables=variables.room_count + variables.lab_count,
                variables=len(model.proto.variables),
                constraints=len(model.proto.constraints),
                build_seconds=round(built - started, 2),
                solve_seconds=round(solver.wall_time, 2),
                status=solver.status_name(status),
            )
        )
        if status in FEASIBLE:
            try:
                run.sessions = sessions_of(solver, variables, data)
            except RoomAssignmentError as error:
                run.status = cp_model.UNKNOWN
                run.error = str(error)
            return run
        if status != cp_model.INFEASIBLE or not variables.truncated:
            return run

    return run
