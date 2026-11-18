"""Las dos formas medidas de llegar a una primera solución sin duras en la carga de un departamento (docs/arquitectura/
solver.md, «Tamaño de la instancia real»).

Las dos parten de la misma primera vuelta: salones en dos fases y cada grupo hasta el primer nivel del patrón que cabe,
con la asignación relajada (a lo más una opción por grupo) y el objetivo de programar a la mayor cantidad de grupos.

- `by_tiers`: los grupos que no quedaron ganan un nivel del patrón (PS-04) y se resuelve todo otra vez, con la
  solución anterior como pista (`add_hint`), hasta que quedan todos. Si ninguno de ellos puede ganar más niveles, los
  ganan los que comparten docente o cohorte con ellos; si tampoco, se detiene.
- `lns`: búsqueda por vecindarios. Para cada grupo que no quedó, solo él y los que comparten docente o cohorte con él
  se mueven, con todos sus niveles; los demás grupos programados entran como fijos con sus sesiones. El modelo de cada
  vuelta es chico y la solución nunca empeora: la pista es la de antes y solo se acepta si programa más grupos.

Ninguna tiene objetivo blando todavía (7.6); 7.7 decide cuál usa el motor y les agrega el avance y la relajación.
"""

import time
from collections import defaultdict
from dataclasses import dataclass, field

from ortools.sat.python import cp_model

from app.contract.input import GroupInput, SessionSlot, SolverInput
from app.contract.output import ProposedSession

from .constraints import add_hard_constraints
from .data import ModelData
from .phases import FEASIBLE
from .solution import chosen_option, sessions_of
from .variables import BuildBudget, DomainOptions, Variables, build_variables

#: La opción elegida de un grupo, para dar la pista: (reparto de días, bloque de inicio).
OptionKey = tuple[tuple[tuple[int, int], ...], int]


@dataclass
class Step:
    """Una vuelta de la búsqueda."""

    groups: int
    options: int
    variables: int
    constraints: int
    build_seconds: float
    solve_seconds: float
    placed: int
    status: str


@dataclass
class Search:
    """Lo que lleva la búsqueda: las sesiones y la opción de cada grupo programado, y las vueltas."""

    sessions: dict[str, list[ProposedSession]] = field(default_factory=dict)
    chosen: dict[str, OptionKey] = field(default_factory=dict)
    unplaced: list[str] = field(default_factory=list)
    steps: list[Step] = field(default_factory=list)
    first_feasible_seconds: float | None = None

    @property
    def all_sessions(self) -> list[ProposedSession]:
        return [session for sessions in self.sessions.values() for session in sessions]


def by_tiers(data: ModelData, time_limit: float, workers: int = 0, seed: int = 0, round_seconds: float = 15) -> Search:
    started = time.perf_counter()
    deadline = started + time_limit
    search = Search()
    extra: dict[str, int] = {}

    while time.perf_counter() < deadline:
        round_deadline = min(deadline, time.perf_counter() + round_seconds)
        options = DomainOptions(extra_tiers=dict(extra))
        variables, solved = _place_most(data, options, search, round_deadline, workers, seed)
        if solved is None:
            break
        _keep(search, solved, variables, data, replace=True)
        if not search.unplaced:
            search.first_feasible_seconds = time.perf_counter() - started
            break
        grow = [group_id for group_id in search.unplaced if variables.groups[group_id].truncated]
        if not grow:
            grow = [
                group_id for group_id in _with_neighbours(data, search.unplaced) if variables.groups[group_id].truncated
            ]
        if not grow:
            break
        for group_id in grow:
            extra[group_id] = extra.get(group_id, 0) + 1

    return search


def lns(data: ModelData, time_limit: float, workers: int = 0, seed: int = 0, step_seconds: float = 3) -> Search:
    started = time.perf_counter()
    deadline = started + time_limit
    search = Search()
    variables, solved = _place_most(data, DomainOptions(), search, deadline, workers, seed)
    if solved is None:
        return search
    _keep(search, solved, variables, data, replace=True)

    pending = list(search.unplaced)
    while pending and time.perf_counter() < deadline:
        target = pending.pop(0)
        neighbourhood = [target, *(group for group in _neighbours(data, target) if group in search.sessions)]
        sub = ModelData.of(_sub_input(data.source, search, set(neighbourhood)))
        before = sum(group in search.sessions for group in neighbourhood)
        step_deadline = min(deadline, time.perf_counter() + step_seconds)
        # El grupo que no quedó, con todos sus niveles; sus vecinos, con uno más del que necesitan.
        options = DomainOptions(extra_tiers={target: 99, **dict.fromkeys(neighbourhood[1:], 1)})
        variables, solved = _place_most(sub, options, search, step_deadline, workers, seed)
        if solved is None:
            continue
        placed = sum(chosen_option(solved, domain) is not None for domain in variables.groups.values())
        if placed > before:
            _keep(search, solved, variables, sub, replace=False)
    search.unplaced = [group.id for group in data.movable if group.id not in search.sessions]
    if not search.unplaced:
        search.first_feasible_seconds = time.perf_counter() - started

    return search


def _place_most(
    data: ModelData, options: DomainOptions, search: Search, deadline: float, workers: int, seed: int
) -> tuple[Variables, cp_model.CpSolver | None]:
    """Arma y resuelve con la asignación relajada, maximizando los grupos programados, con la pista de lo que lleva la
    búsqueda. Devuelve el solver si encontró solución."""
    built_at = time.perf_counter()
    model = cp_model.CpModel()
    variables = build_variables(model, data, options, BuildBudget(deadline=deadline))
    add_hard_constraints(model, variables, data, relaxed=True)
    literals = []
    for domain in variables.groups.values():
        hint = search.chosen.get(domain.id)
        for option in domain.options:
            literals.append(option.literal)
            if hint is not None:
                model.add_hint(option.literal, (option.spread, option.block) == hint)
    model.maximize(cp_model.LinearExpr.sum(literals))
    solve_at = time.perf_counter()

    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = max(0.1, deadline - solve_at)
    solver.parameters.num_workers = workers
    solver.parameters.random_seed = seed
    status = solver.solve(model)
    feasible = status in FEASIBLE
    search.steps.append(
        Step(
            groups=len(variables.groups),
            options=variables.option_count,
            variables=len(model.proto.variables),
            constraints=len(model.proto.constraints),
            build_seconds=round(solve_at - built_at, 2),
            solve_seconds=round(solver.wall_time, 2),
            placed=int(solver.objective_value) if feasible else 0,
            status=solver.status_name(status),
        )
    )

    return variables, solver if feasible else None


def _keep(search: Search, solver: cp_model.CpSolver, variables: Variables, data: ModelData, replace: bool) -> None:
    """Guarda las sesiones de los grupos del modelo resuelto: todos con `replace`, o solo los del vecindario."""
    sessions: dict[str, list[ProposedSession]] = defaultdict(list)
    for session in sessions_of(solver, variables, data):
        sessions[session.group_id].append(session)
    if replace:
        search.sessions = {}
        search.chosen = {}
    for domain in variables.groups.values():
        option = chosen_option(solver, domain)
        search.sessions.pop(domain.id, None)
        search.chosen.pop(domain.id, None)
        if option is not None:
            search.sessions[domain.id] = sessions[domain.id]
            search.chosen[domain.id] = (option.spread, option.block)
    search.unplaced = [domain.id for domain in variables.groups.values() if domain.id not in search.sessions]


def _neighbours(data: ModelData, group_id: str) -> list[str]:
    """Los grupos movibles que comparten docente o una cohorte con el grupo."""
    group = data.groups[group_id]
    cohorts = set(group.cohorts)

    return [
        other.id
        for other in data.movable
        if other.id != group_id
        and ((group.teacher_id is not None and other.teacher_id == group.teacher_id) or cohorts & set(other.cohorts))
    ]


def _with_neighbours(data: ModelData, group_ids: list[str]) -> list[str]:
    found = dict.fromkeys(group_ids)
    for group_id in group_ids:
        found.update(dict.fromkeys(_neighbours(data, group_id)))

    return list(found)


def _sub_input(source: SolverInput, search: Search, movable: set[str]) -> SolverInput:
    """La entrada de un vecindario: sus grupos movibles; los demás programados, fijos con sus sesiones; los demás sin
    programar, fuera."""
    groups: list[GroupInput] = []
    for group in source.groups:
        if not group.movable or group.id in movable:
            groups.append(group)
        elif group.id in search.sessions:
            slots = [
                SessionSlot(
                    day=session.day,
                    starts_at=session.starts_at,
                    ends_at=session.ends_at,
                    kind=session.kind,
                    room_id=session.room_id,
                )
                for session in search.sessions[group.id]
            ]
            groups.append(group.model_copy(update={"movable": False, "fixed_sessions": slots, "current_sessions": []}))

    return source.model_copy(update={"groups": groups})
