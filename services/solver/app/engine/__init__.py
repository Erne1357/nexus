"""Motores del servicio. Hoy solo el de prueba; el modelo CP-SAT (7.4 a 7.7) se registra aquí."""

from .base import Engine, RunCancelled, RunContext
from .trivial import TrivialEngine

ENGINES: dict[str, type[Engine]] = {TrivialEngine.key: TrivialEngine}


def make_engine(key: str) -> Engine:
    """Crea el motor registrado con esa clave (`SOLVER_ENGINE`)."""
    try:
        return ENGINES[key]()
    except KeyError:
        known = ", ".join(sorted(ENGINES))
        raise ValueError(f"Motor desconocido: {key!r} (disponibles: {known}).") from None


__all__ = ["ENGINES", "Engine", "RunCancelled", "RunContext", "TrivialEngine", "make_engine"]
