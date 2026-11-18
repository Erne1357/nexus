"""La interfaz de un motor dentro del servicio."""

from collections.abc import Callable
from dataclasses import dataclass, field
from typing import Protocol

from app.contract import SolverInput, SolverOutput


class RunCancelled(Exception):
    """El motor vio la bandera de cancelación y se detuvo."""


@dataclass
class RunContext:
    """Lo que la corrida le da al motor: avance, cancelación y los límites del servicio."""

    #: Hilos de CP-SAT; 0 deja que el motor use los núcleos disponibles.
    threads: int = 0
    #: Límite de tiempo por defecto del servicio, en segundos.
    default_time_limit: int = 60
    #: Variables que puede tener el modelo de una corrida (`SOLVER_MAX_MODEL_VARIABLES`).
    max_model_variables: int = 1_500_000
    #: `report(percent, best_score)`: avance de 0 a 100 y mejor puntaje blando hasta el momento.
    report: Callable[[int, int | None], None] = field(default=lambda percent, best_score: None)
    #: Devuelve `True` cuando alguien pidió cancelar la corrida.
    is_cancelled: Callable[[], bool] = field(default=lambda: False)

    def check_cancelled(self) -> None:
        if self.is_cancelled():
            raise RunCancelled


class Engine(Protocol):
    """Un motor recibe la entrada validada y devuelve la salida del contrato; no guarda estado entre corridas."""

    key: str
    version: str

    def solve(self, data: SolverInput, context: RunContext) -> SolverOutput: ...
