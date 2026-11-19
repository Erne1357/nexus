"""Configuración del servicio, leída de variables de entorno."""

import os
from collections.abc import Mapping
from dataclasses import dataclass


class ConfigError(RuntimeError):
    """Falta una variable obligatoria o trae un valor inválido."""


@dataclass(frozen=True)
class Settings:
    #: Token compartido con Nexus (`SOLVER_TOKEN`); se exige en cada petición salvo la de salud.
    token: str
    #: Límite de tiempo por defecto, en segundos, para los motores que lo necesiten (`SOLVER_DEFAULT_TIME_LIMIT`).
    default_time_limit: int = 60
    #: Hilos de CP-SAT (`SOLVER_THREADS`); 0 deja que el motor use los núcleos disponibles.
    threads: int = 0
    #: Variables que puede tener el modelo CP-SAT de una corrida (`SOLVER_MAX_MODEL_VARIABLES`); si las pasa, la corrida
    #: falla antes de armarlo todo. Las corridas comparten un proceso (y su `mem_limit`), y cada variable cuesta
    #: unos cientos de bytes en Python (docs/arquitectura/solver.md, «Tamaño de la instancia real»).
    max_model_variables: int = 1_500_000
    #: Corridas simultáneas (en cola o en curso) antes de responder 429 (`SOLVER_MAX_CONCURRENT_RUNS`).
    max_concurrent_runs: int = 2
    #: Corridas terminadas que se conservan en memoria (`SOLVER_MAX_STORED_RUNS`); las más viejas se descartan.
    max_stored_runs: int = 50
    #: Motor que atiende las corridas (`SOLVER_ENGINE`): `cpsat` (el modelo CP-SAT, por omisión desde la 7.10) o
    #: `trivial` (el de prueba, solo si se pide, para pruebas y CI).
    engine: str = "cpsat"

    @classmethod
    def from_env(cls, env: Mapping[str, str] | None = None) -> "Settings":
        env = os.environ if env is None else env
        token = env.get("SOLVER_TOKEN", "").strip()
        if token == "":
            raise ConfigError("SOLVER_TOKEN es obligatoria: define el token compartido con Nexus.")

        return cls(
            token=token,
            default_time_limit=_int(env, "SOLVER_DEFAULT_TIME_LIMIT", cls.default_time_limit, 1),
            threads=_int(env, "SOLVER_THREADS", cls.threads, 0),
            max_model_variables=_int(env, "SOLVER_MAX_MODEL_VARIABLES", cls.max_model_variables, 1),
            max_concurrent_runs=_int(env, "SOLVER_MAX_CONCURRENT_RUNS", cls.max_concurrent_runs, 1),
            max_stored_runs=_int(env, "SOLVER_MAX_STORED_RUNS", cls.max_stored_runs, 1),
            engine=env.get("SOLVER_ENGINE", cls.engine).strip() or cls.engine,
        )


def _int(env: Mapping[str, str], name: str, default: int, minimum: int) -> int:
    raw = env.get(name, "").strip()
    if raw == "":
        return default
    try:
        value = int(raw)
    except ValueError:
        raise ConfigError(f"{name} debe ser un entero (recibido: {raw!r}).") from None
    if value < minimum:
        raise ConfigError(f"{name} debe ser un entero de {minimum} en adelante (recibido: {value}).")

    return value
