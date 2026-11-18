"""El modelo duro de punta a punta, sin objetivo: armar, resolver la fase A y repartir los salones (fase B).

Primero con cada grupo hasta el primer nivel del patrón donde alguna opción cabe; si eso es infactible y algún grupo
dejó niveles sin revisar, una segunda vuelta con todos los niveles. Sin objetivo, CP-SAT se detiene en la primera
solución factible. La resolución con objetivo, avance y relajación es de la tarea 7.7, que parte de aquí.
"""

import time
from dataclasses import dataclass, field

from ortools.sat.python import cp_model

from app.contract.output import ProposedSession

from .constraints import add_hard_constraints
from .data import ModelData
from .solution import sessions_of
from .variables import BuildBudget, BuildTimeout, DomainOptions, build_variables

FEASIBLE = (cp_model.OPTIMAL, cp_model.FEASIBLE)


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

    @property
    def feasible(self) -> bool:
        return self.status in FEASIBLE


def solve_hard(
    data: ModelData,
    time_limit: float,
    workers: int = 0,
    seed: int = 0,
    pool_rooms: bool = True,
    tiers_by_need: bool = True,
) -> HardRun:
    """La primera solución sin violaciones duras, o el estado de CP-SAT si no la hay en el tiempo. Sin
    `pool_rooms` ni `tiers_by_need` es el modelo exacto de 7.4 y 7.5 (un `z` por ventana y salón, todos los niveles),
    que las pruebas usan para comparar."""
    deadline = time.perf_counter() + time_limit
    run = HardRun(status=cp_model.UNKNOWN)

    for all_tiers in (False, True) if tiers_by_need else (True,):
        started = time.perf_counter()
        model = cp_model.CpModel()
        try:
            variables = build_variables(
                model, data, DomainOptions(pool_rooms=pool_rooms, all_tiers=all_tiers), BuildBudget(deadline=deadline)
            )
        except BuildTimeout:
            run.status = cp_model.UNKNOWN
            return run
        add_hard_constraints(model, variables, data)
        built = time.perf_counter()

        solver = cp_model.CpSolver()
        solver.parameters.max_time_in_seconds = max(0.1, deadline - built)
        solver.parameters.num_workers = workers
        solver.parameters.random_seed = seed
        status = solver.solve(model)
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
            run.sessions = sessions_of(solver, variables, data)
            return run
        if status != cp_model.INFEASIBLE or not variables.truncated:
            return run

    return run
