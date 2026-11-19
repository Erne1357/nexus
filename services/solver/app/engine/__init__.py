"""Motores del servicio: el modelo CP-SAT (`cpsat`, 7.7; el de omisión) y el de prueba (`trivial`). `SOLVER_ENGINE`
elige uno."""

from .base import Engine, RunCancelled, RunContext
from .cpsat import CpSatEngine
from .trivial import TrivialEngine

ENGINES: dict[str, type[Engine]] = {TrivialEngine.key: TrivialEngine, CpSatEngine.key: CpSatEngine}


def make_engine(key: str) -> Engine:
    """Crea el motor registrado con esa clave (`SOLVER_ENGINE`)."""
    try:
        return ENGINES[key]()
    except KeyError:
        known = ", ".join(sorted(ENGINES))
        raise ValueError(f"Motor desconocido: {key!r} (disponibles: {known}).") from None


__all__ = ["ENGINES", "CpSatEngine", "Engine", "RunCancelled", "RunContext", "TrivialEngine", "make_engine"]
