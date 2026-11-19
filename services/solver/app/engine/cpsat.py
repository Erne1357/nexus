"""El motor CP-SAT del servicio (tarea 7.7): el modelo de `app/model` de punta a punta (`model.solve`).

Toma de la corrida (`RunContext`) los hilos (`SOLVER_THREADS` u `options.threads` de la entrada; con 0, los núcleos
del contenedor), el tope de variables y la cancelación (`SearchLimits.of`), y reporta el avance y el mejor puntaje con
`context.report`. El plazo es el `time_limit_seconds` de la entrada; la semilla, `options.seed` (0 si no la trae). Se
elige con `SOLVER_ENGINE=cpsat`; el predeterminado del servicio sigue siendo `trivial` hasta la 7.10.
"""

from app.contract import SolverInput, SolverOutput
from app.model.phases import SearchLimits
from app.model.solve import solve

from .base import RunContext


class CpSatEngine:
    key = "cpsat"
    version = "1.0.0"

    def solve(self, data: SolverInput, context: RunContext) -> SolverOutput:
        resolution = solve(
            data,
            time_limit=float(data.time_limit_seconds),
            limits=SearchLimits.of(context),
            seed=data.options.seed or 0,
            report=context.report,
            engine=self.key,
            version=self.version,
        )

        return resolution.output
