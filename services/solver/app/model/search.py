"""La búsqueda de una primera solución sin duras en la carga de un departamento: arranque tibio y vecindarios (LNS;
docs/arquitectura/solver.md, «Tamaño de la instancia real»).

1. **Primera vuelta**: salones en dos fases (`rooms.py`) y cada grupo con su primer nivel del patrón que cabe y los de
   una hora por día (`DomainOptions`), con la asignación relajada (a lo más una opción por grupo) y el objetivo de
   programar la mayor cantidad. La pista (`add_hint`) es el horario actual de cada grupo cuando es una opción del
   patrón (`current_sessions`, PS-07).
2. **Vecindarios**: para cada grupo que no quedó, solo él y los que comparten docente o cohorte con él se mueven
   (`_neighbourhood`, que crece en cada intento); los demás programados entran como fijos con sus sesiones. El modelo de
   cada vuelta es chico y la solución nunca empeora: la pista es la de antes y solo se acepta si programa más grupos.

Sin objetivo blando todavía (7.6); 7.7 la registra como motor con el avance y la relajación.
"""

import random
import time
from collections import defaultdict
from collections.abc import Callable
from dataclasses import dataclass, field

from ortools.sat.python import cp_model

from app.contract.input import GroupInput, SessionSlot, SolverInput
from app.contract.output import ProposedSession

from .constraints import add_hard_constraints
from .data import ModelData
from .objective import add_objective
from .pattern import minutes
from .phases import FEASIBLE, SearchLimits, run_solver
from .room_model import improve_rooms_scored
from .rooms import RoomAssignmentError
from .score import Score, SoftScorer
from .solution import chosen_option, sessions_of
from .variables import BuildTimeout, DomainOptions, ModelTooLarge, Occupancy, Variables, build_variables

#: Salones con `z` de un día de 2 horas en un vecindario: los más ajustados (`DomainOptions.long_window_rooms`).
LONG_WINDOW_ROOMS = 12

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
    #: El nivel del patrón de la opción de cada grupo programado (PS-04).
    tiers: dict[str, int] = field(default_factory=dict)
    unplaced: list[str] = field(default_factory=list)
    steps: list[Step] = field(default_factory=list)
    first_feasible_seconds: float | None = None

    @property
    def all_sessions(self) -> list[ProposedSession]:
        return [session for sessions in self.sessions.values() for session in sessions]


def lns(
    data: ModelData,
    time_limit: float,
    limits: SearchLimits | None = None,
    seed: int = 0,
    step_seconds: float = 3,
    first_share: float = 0.25,
) -> Search:
    """La primera vuelta tiene a lo más `first_share` del tiempo (con pocos hilos no alcanza a probar que es lo mejor
    de su dominio y gastaría todo); el resto es para los vecindarios, de `step_seconds` cada uno.

    `limits` trae los hilos, el tope de variables (la primera vuelta lanza `ModelTooLarge` si lo pasa; un vecindario
    que lo pasa se descarta) y la cancelación, que se revisa al armar, mientras CP-SAT resuelve y entre vecindarios.
    Los plazos son de reloj: con la misma semilla, una máquina más lenta puede llegar a otra solución."""
    limits = limits or SearchLimits()
    started = time.perf_counter()
    deadline = started + time_limit
    search = Search()
    search.chosen = _current_options(data)
    first_deadline = started + max(step_seconds, time_limit * first_share)
    variables, solved = _place_most(data, DomainOptions(), search, min(deadline, first_deadline), limits, seed)
    if solved is None or not _keep(search, solved, variables, data, replace=True):
        search.sessions, search.chosen = {}, {}
        search.unplaced = [group.id for group in data.movable]
        return search

    pending = [(group.id, 0) for group in data.movable if group.id not in search.sessions]
    while pending and time.perf_counter() < deadline:
        limits.tick()
        target, attempt = pending.pop(0)
        if target in search.sessions:
            # Lo programó el vecindario de otro grupo.
            continue
        movable = _neighbourhood(data, search, target, min(attempt, MAX_LEVEL))
        sub = ModelData.of(_sub_input(data.source, search, set(movable)))
        before = sum(group in search.sessions for group in movable)
        # Cada intento, más tiempo y otra semilla: el vecindario grande no siempre se resuelve en el primero.
        step_deadline = min(deadline, time.perf_counter() + step_seconds * (attempt + 1))
        options = DomainOptions(extra_tiers=movable, long_window_rooms=LONG_WINDOW_ROOMS)
        try:
            variables, solved = _place_most(sub, options, search, step_deadline, limits, seed + attempt)
        except ModelTooLarge:
            variables, solved = Variables(groups={}, teachers=Occupancy(), rooms=Occupancy(), cohorts=Occupancy()), None
        placed = (
            0
            if solved is None
            else sum(chosen_option(solved, domain) is not None for domain in variables.groups.values())
        )
        if solved is not None and placed > before and _keep(search, solved, variables, sub, replace=False):
            # Un vecino que quedó fuera para que entraran más vuelve a la fila.
            pending.extend((group, 0) for group in movable if group != target and group not in search.sessions)
        elif attempt < MAX_ATTEMPTS:
            pending.append((target, attempt + 1))
    search.unplaced = [group.id for group in data.movable if group.id not in search.sessions]
    if not search.unplaced:
        search.first_feasible_seconds = time.perf_counter() - started

    return search


#: Cuántas veces se agranda el vecindario de un grupo que no se pudo programar.
MAX_LEVEL = 2

#: Cuántas veces se vuelve a intentar un grupo: las de más son con el vecindario más grande, más tiempo y otra semilla.
MAX_ATTEMPTS = 5

#: Todos los niveles del patrón (`DomainOptions.extra_tiers`).
ALL_TIERS = 99


def _neighbourhood(data: ModelData, search: Search, target: str, level: int) -> dict[str, int]:
    """Los grupos que se mueven para programar `target`, con los niveles de más de cada uno.

    - 0: el grupo con todos sus niveles y los programados que comparten docente o cohorte con él, con los de una hora
      por día (otros días, sin días de 2 horas);
    - 1: esos vecinos también con todos sus niveles;
    - 2: además, los vecinos de los vecinos con los de una hora por día y, si el grupo lleva laboratorio, los demás
      grupos con laboratorio de su campus, que compiten por los mismos salones.
    """
    first = [group for group in _neighbours(data, target) if group in search.sessions]
    movable = {target: ALL_TIERS, **dict.fromkeys(first, ALL_TIERS if level >= 1 else 0)}
    if level >= 2:
        for group in first:
            for other in _neighbours(data, group):
                if other in search.sessions:
                    movable.setdefault(other, 0)
        group = data.groups[target]
        if group.needs_lab and group.practice_hours > 0:
            for other in data.movable:
                if other.campus_code == group.campus_code and other.needs_lab and other.id in search.sessions:
                    movable.setdefault(other.id, 0)

    return movable


def _current_options(data: ModelData) -> dict[str, OptionKey]:
    """La opción de cada grupo movible que ya tiene un horario con el patrón (`current_sessions`): la pista de la
    primera vuelta (PS-07). Un horario que no es una opción del patrón (sesiones de 2 horas, horas distintas cada día)
    no da pista."""
    chosen: dict[str, OptionKey] = {}
    for group in data.movable:
        grid = data.grids[group.campus_code]
        if not group.current_sessions:
            continue
        by_day: dict[int, list[int]] = defaultdict(list)
        for slot in group.current_sessions:
            start, end = minutes(slot.starts_at), minutes(slot.ends_at)
            if (start - grid.start) % grid.block_minutes or (end - start) % grid.unit_minutes:
                break
            by_day[slot.day].extend(range(start, end, grid.unit_minutes))
        else:
            units = {day: sorted(day_units) for day, day_units in by_day.items()}
            contiguous = all(
                day_units
                == list(range(day_units[0], day_units[0] + len(day_units) * grid.unit_minutes, grid.unit_minutes))
                for day_units in units.values()
            )
            starts = {day_units[0] for day_units in units.values()}
            if contiguous and len(starts) == 1:
                block = (starts.pop() - grid.start) // grid.block_minutes
                chosen[group.id] = (tuple((day, len(units[day])) for day in sorted(units)), block)

    return chosen


def _place_most(
    data: ModelData, options: DomainOptions, search: Search, deadline: float, limits: SearchLimits, seed: int
) -> tuple[Variables, cp_model.CpSolver | None]:
    """Arma y resuelve con la asignación relajada, maximizando los grupos programados, con la pista de lo que lleva la
    búsqueda. Devuelve el solver si encontró solución."""
    built_at = time.perf_counter()
    model = cp_model.CpModel()
    try:
        variables = build_variables(model, data, options, limits.budget(deadline))
    except BuildTimeout:
        # Se acabó el plazo al armar: la vuelta no da nada y lo que ya estaba programado se queda.
        return Variables(groups={}, teachers=Occupancy(), rooms=Occupancy(), cohorts=Occupancy()), None
    add_hard_constraints(model, variables, data, relaxed=True)
    literals = []
    for domain in variables.groups.values():
        literals.extend(option.literal for option in domain.options)
        hint = search.chosen.get(domain.id)
        if hint is not None and any((option.spread, option.block) == hint for option in domain.options):
            for option in domain.options:
                model.add_hint(option.literal, (option.spread, option.block) == hint)
    model.maximize(cp_model.LinearExpr.sum(literals))
    solve_at = time.perf_counter()

    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = max(0.1, deadline - solve_at)
    solver.parameters.random_seed = seed
    status = run_solver(solver, model, limits)
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


def _keep(search: Search, solver: cp_model.CpSolver, variables: Variables, data: ModelData, replace: bool) -> bool:
    """Guarda las sesiones de los grupos del modelo resuelto: todos con `replace`, o solo los del vecindario. Si la
    fase B no reparte los salones (no debería: la fase A lo garantiza), no guarda nada y devuelve `False`: la búsqueda
    se queda con lo que tenía."""
    sessions: dict[str, list[ProposedSession]] = defaultdict(list)
    try:
        proposed = sessions_of(solver, variables, data)
    except RoomAssignmentError:
        return False
    for session in proposed:
        sessions[session.group_id].append(session)
    if replace:
        search.sessions = {}
        search.chosen = {}
        search.tiers = {}
    for domain in variables.groups.values():
        option = chosen_option(solver, domain)
        search.sessions.pop(domain.id, None)
        search.chosen.pop(domain.id, None)
        search.tiers.pop(domain.id, None)
        if option is not None:
            search.sessions[domain.id] = sessions[domain.id]
            search.chosen[domain.id] = (option.spread, option.block)
            search.tiers[domain.id] = option.tier
    search.unplaced = [group.id for group in data.movable if group.id not in search.sessions]

    return True


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


#: Grupos por vecindario de la mejora: los de unos docentes completos, más los que comparten cohorte con ellos.
IMPROVE_GROUPS = 24

#: Segundos de CP-SAT por vecindario de la mejora.
IMPROVE_STEP_SECONDS = 0.4

#: Segundos para ajustar los salones de un vecindario (SC-03 y SC-06) antes de compararlo.
IMPROVE_ROOM_SECONDS = 0.3


@dataclass
class Improvement:
    """Lo que hizo la mejora: el puntaje al empezar y el mejor, los vecindarios revisados y los aceptados."""

    initial: Score
    best: Score
    steps: int = 0
    accepted: int = 0


def improve(
    data: ModelData,
    search: Search,
    deadline: float,
    limits: SearchLimits | None = None,
    seed: int = 0,
    report: Callable[[Score, float], None] | None = None,
) -> Improvement:
    """La búsqueda por vecindarios con objetivo (7.6), sobre lo que ya programó `lns`.

    Cada vecindario son los grupos programados de unos docentes y los que comparten cohorte con ellos; los demás
    programados entran fijos con sus sesiones, pero siguen en el alcance del objetivo. Sus grupos se programan todos
    (asignación exacta: nunca deja fuera a uno que estaba), con sus niveles por necesidad y el que ya tenían, y el
    modelo minimiza SC-01 a SC-09 (`objective.py`) con la pista de lo que tenían. Se acepta solo si el puntaje de toda
    la solución (`score.py`, el de PHP) baja. Así es lexicográfico: primero los grupos programados (lo que dejó `lns`,
    que ningún vecindario quita) y después el puntaje. Para cuando se acaba el plazo o cuando una vuelta entera a los
    vecindarios no mejora nada. `report(mejor, avance)` se llama después de cada vecindario, con el avance del plazo
    de 0 a 1."""
    limits = limits or SearchLimits()
    scorer = SoftScorer(data.source)
    scope = {group.id for group in data.movable}
    best = scorer.score(search.all_sessions)
    result = Improvement(initial=best, best=best)
    started = time.perf_counter()
    rng = random.Random(seed)
    idle = 0
    while time.perf_counter() < deadline:
        batches = _improvement_batches(data, search, rng)
        if not batches or idle >= len(batches):
            break
        for movable in batches:
            if time.perf_counter() >= deadline or idle >= len(batches):
                break
            limits.tick()
            result.steps += 1
            candidate = _improve_step(data, search, movable, scope, deadline, limits, seed + result.steps)
            idle += 1
            if candidate is not None:
                sessions = {**search.sessions, **candidate[0]}
                proposed = [session for items in sessions.values() for session in items]
                # Los salones del vecindario los repartió la fase B sin ver edificios: se ajustan antes de comparar.
                room_deadline = min(deadline, time.perf_counter() + IMPROVE_ROOM_SECONDS)
                proposed, score = improve_rooms_scored(
                    data, proposed, room_deadline, limits, scope, groups=movable, scorer=scorer
                )
                if score.total < best.total:
                    search.sessions = defaultdict(list)
                    for session in proposed:
                        search.sessions[session.group_id].append(session)
                    search.sessions = dict(search.sessions)
                    search.chosen.update(candidate[1])
                    search.tiers.update(candidate[2])
                    best = score
                    result.accepted += 1
                    idle = 0
            if report is not None:
                elapsed = time.perf_counter() - started
                report(best, min(1.0, elapsed / max(1e-9, deadline - started)))
    result.best = best

    return result


def _improvement_batches(data: ModelData, search: Search, rng: random.Random) -> list[set[str]]:
    """Los vecindarios de una vuelta: los docentes en un orden al azar (con la semilla), juntados hasta
    `IMPROVE_GROUPS` grupos, cada uno con los programados que comparten cohorte con los suyos; los grupos sin docente,
    por cohorte."""
    by_teacher: dict[str, list[str]] = defaultdict(list)
    loose: list[str] = []
    for group in data.movable:
        if group.id not in search.sessions:
            continue
        if group.teacher_id is None:
            loose.append(group.id)
        else:
            by_teacher[group.teacher_id].append(group.id)
    members: dict[str, list[str]] = defaultdict(list)
    for group in data.movable:
        if group.id in search.sessions:
            for key in group.cohorts:
                members[key].append(group.id)
    units = [by_teacher[teacher] for teacher in sorted(by_teacher)]
    units += [loose[i : i + IMPROVE_GROUPS] for i in range(0, len(loose), IMPROVE_GROUPS)]
    rng.shuffle(units)

    batches: list[set[str]] = []
    current: set[str] = set()
    for unit in units:
        current.update(unit)
        if len(current) >= IMPROVE_GROUPS:
            batches.append(current)
            current = set()
    if current:
        batches.append(current)
    for batch in batches:
        for group_id in list(batch):
            for key in data.groups[group_id].cohorts:
                batch.update(members[key])

    return batches


def _improve_step(
    data: ModelData,
    search: Search,
    movable: set[str],
    scope: set[str],
    deadline: float,
    limits: SearchLimits,
    seed: int,
) -> tuple[dict[str, list[ProposedSession]], dict[str, OptionKey], dict[str, int]] | None:
    """Un vecindario: sus grupos con el objetivo y lo demás fijo. Devuelve sus sesiones, opciones y niveles, o `None`
    si no hubo solución a tiempo."""
    step_deadline = min(deadline, time.perf_counter() + IMPROVE_STEP_SECONDS)
    sub = ModelData.of(_sub_input(data.source, search, movable))
    model = cp_model.CpModel()
    options = DomainOptions(
        keep_tiers={group: search.tiers[group] for group in movable if group in search.tiers},
        long_window_rooms=LONG_WINDOW_ROOMS,
    )
    try:
        variables = build_variables(model, sub, options, limits.budget(step_deadline))
    except (BuildTimeout, ModelTooLarge):
        return None
    if variables.unplaceable():
        return None
    add_hard_constraints(model, variables, sub)
    objective = add_objective(model, variables, sub, scope=scope)
    model.minimize(objective.expression())
    for domain in variables.groups.values():
        hint = search.chosen.get(domain.id)
        for option in domain.options:
            model.add_hint(option.literal, (option.spread, option.block) == hint)
    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = max(0.05, step_deadline - time.perf_counter())
    solver.parameters.random_seed = seed
    if run_solver(solver, model, limits) not in FEASIBLE:
        return None
    try:
        proposed = sessions_of(solver, variables, sub)
    except RoomAssignmentError:
        return None
    sessions: dict[str, list[ProposedSession]] = {group: [] for group in variables.groups}
    for session in proposed:
        sessions[session.group_id].append(session)
    chosen: dict[str, OptionKey] = {}
    tiers: dict[str, int] = {}
    for domain in variables.groups.values():
        option = chosen_option(solver, domain)
        if option is None:
            return None
        chosen[domain.id] = (option.spread, option.block)
        tiers[domain.id] = option.tier

    return sessions, chosen, tiers
