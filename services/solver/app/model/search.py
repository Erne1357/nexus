"""La búsqueda de una primera solución sin duras en la carga de un departamento: arranque tibio y vecindarios (LNS;
docs/arquitectura/solver.md, «Tamaño de la instancia real»).

0. **Pista de la entrada** (`hint.py`): los grupos con pista válida (`hint_sessions`, la salida de la heurística)
   entran ya programados, con su opción y sus salones.
1. **Primera vuelta**, solo de los que faltan (con los de la pista fijos): salones en dos fases (`rooms.py`) y cada
   grupo con su primer nivel del patrón que cabe (`DomainOptions`), con la asignación relajada (a lo más una opción por
   grupo) y el objetivo de programar la mayor cantidad. La pista de CP-SAT (`add_hint`) es el horario actual de cada
   grupo cuando es una opción del patrón (`current_sessions`, PS-07).
2. **Vecindarios**: para cada grupo que no quedó, solo él y los que comparten docente o cohorte con él se mueven
   (`_neighbourhood`, que crece en cada intento); los demás programados entran como fijos con sus sesiones. El modelo de
   cada vuelta es chico y la solución nunca empeora: la pista es lo que llevaba cada grupo (`_add_hints`: su opción en
   `y` y sus salones en `z`) y solo se acepta si programa más grupos.

3. **Mejora** (`improve`, 7.6): sobre lo que programó `lns`, vecindarios con el objetivo blando que nunca quitan un
   grupo ni lo llevan a un nivel del patrón peor (PS-10).
"""

import random
import time
from collections import defaultdict
from collections.abc import Callable, Mapping
from dataclasses import dataclass, field

from ortools.sat.python import cp_model

from app.contract.input import GroupInput, SessionSlot, SolverInput, TeacherInput
from app.contract.output import ProposedSession

from .constraints import add_hard_constraints
from .data import ModelData
from .hint import Hint
from .objective import add_objective
from .pattern import minutes
from .phases import FEASIBLE, SearchLimits, run_solver, solve_until
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
    report: Callable[[int], None] | None = None,
    hint: Hint | None = None,
) -> Search:
    """La primera vuelta tiene a lo más `first_share` del tiempo (con pocos hilos no alcanza a probar que es lo mejor
    de su dominio y gastaría todo); el resto es para los vecindarios, de `step_seconds` cada uno.

    Con `hint` (`hint.read_hint`), sus grupos entran ya programados y la primera vuelta solo arma a los que faltan, con
    los de la pista fijos; si la pista los trae a todos, no hay primera vuelta. Si la primera vuelta no da nada, la
    pista se queda y los vecindarios siguen con los que faltan.

    `limits` trae los hilos, el tope de variables (la primera vuelta lanza `ModelTooLarge` si lo pasa; un vecindario
    que lo pasa se descarta) y la cancelación, que se revisa al armar, mientras CP-SAT resuelve y entre vecindarios.
    Los plazos son de reloj: con la misma semilla, una máquina más lenta puede llegar a otra solución.

    `report(programados)` se llama con cada solución intermedia de la primera vuelta y después de cada vecindario
    (7.7)."""
    limits = limits or SearchLimits()
    started = time.perf_counter()
    deadline = started + time_limit
    search = Search()
    search.chosen = _current_options(data)
    for group_id, hinted in (hint.groups if hint is not None else {}).items():
        search.sessions[group_id] = list(hinted.sessions)
        search.chosen[group_id] = hinted.chosen
        search.tiers[group_id] = hinted.tier
    hinted_count = len(search.sessions)
    if report is not None and hinted_count:
        report(hinted_count)
    missing = {group.id for group in data.movable if group.id not in search.sessions}
    if missing:
        first_deadline = started + max(step_seconds, time_limit * first_share)
        # La primera vuelta lleva solo el primer nivel que cabe de cada grupo, así que lo que programa queda en su
        # patrón (PS-10); los otros días y los días de 2 horas llegan con los vecindarios, a los que no quedaron.
        first_round = DomainOptions(one_hour_tiers=False)
        first_data = ModelData.of(_sub_input(data.source, search, missing)) if hinted_count else data

        def first_report(placed: int) -> None:
            report(hinted_count + placed)

        variables, solved = _place_most(
            first_data,
            first_round,
            search,
            min(deadline, first_deadline),
            limits,
            seed,
            first_report if report is not None else None,
        )
        kept = solved is not None and _keep(search, solved, variables, first_data, replace=not hinted_count)
        if not kept and not hinted_count:
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
        if report is not None:
            report(len(search.sessions))
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
    data: ModelData,
    options: DomainOptions,
    search: Search,
    deadline: float,
    limits: SearchLimits,
    seed: int,
    report: Callable[[int], None] | None = None,
) -> tuple[Variables, cp_model.CpSolver | None]:
    """Arma y resuelve con la asignación relajada, maximizando los grupos programados y, entre soluciones con los
    mismos, el menor nivel del patrón (PS-10), con la pista de lo que lleva la búsqueda. Devuelve el solver si encontró
    solución."""
    built_at = time.perf_counter()
    model = cp_model.CpModel()
    try:
        variables = build_variables(model, data, options, limits.budget(deadline))
    except BuildTimeout:
        # Se acabó el plazo al armar: la vuelta no da nada y lo que ya estaba programado se queda.
        return Variables(groups={}, teachers=Occupancy(), rooms=Occupancy(), cohorts=Occupancy()), None
    add_hard_constraints(model, variables, data, relaxed=True)
    literals = []
    tiers = []
    for domain in variables.groups.values():
        literals.extend(option.literal for option in domain.options)
        tiers.extend(option.tier for option in domain.options)
    _add_hints(model, variables, search, data, only_in_domain=True)
    # PS-10: primero los grupos programados y después el patrón. Cada grupo vale más que la suma de los niveles de
    # todos, así que el nivel solo decide entre soluciones con los mismos programados.
    per_group = 1 + sum(
        max((option.tier for option in domain.options), default=0) for domain in variables.groups.values()
    )
    model.maximize(per_group * cp_model.LinearExpr.sum(literals) - cp_model.LinearExpr.weighted_sum(literals, tiers))
    solve_at = time.perf_counter()

    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = max(0.1, deadline - solve_at)
    solver.parameters.random_seed = seed
    # Los programados salen del valor del objetivo, sin recorrer las opciones en el hilo de CP-SAT: los niveles suman
    # menos que `per_group`, así que es el techo de objetivo / per_group.
    on_solution = None if report is None else lambda callback: report(-(-round(callback.objective_value) // per_group))
    status = run_solver(solver, model, limits, on_solution)
    feasible = status in FEASIBLE
    search.steps.append(
        Step(
            groups=len(variables.groups),
            options=variables.option_count,
            variables=len(model.proto.variables),
            constraints=len(model.proto.constraints),
            build_seconds=round(solve_at - built_at, 2),
            solve_seconds=round(solver.wall_time, 2),
            placed=sum(solver.boolean_value(literal) for literal in literals) if feasible else 0,
            status=solver.status_name(status),
        )
    )

    return variables, solver if feasible else None


def _add_hints(
    model: cp_model.CpModel,
    variables: Variables,
    search: Search,
    data: ModelData,
    chosen: Mapping[str, OptionKey | None] | None = None,
    only_in_domain: bool = False,
) -> None:
    """La pista de CP-SAT (`add_hint`): lo que lleva la búsqueda, que al empezar es la pista de la entrada.

    En cada grupo, la opción de `chosen` (por omisión, la de la búsqueda) en `y` y las demás en cero. Si es la opción
    que tiene en la búsqueda, también sus salones: en las ventanas con `z`, el salón que tiene ese día (si está entre
    los de la ventana; si no, sin pista) y las demás ventanas en cero; en las del conjunto con laboratorio, si su salón
    es espacio de práctica. Con `only_in_domain`, un grupo cuya opción no está en su dominio no lleva pista."""
    keys = search.chosen if chosen is None else chosen
    for domain in variables.groups.values():
        key = keys.get(domain.id)
        option = next((option for option in domain.options if (option.spread, option.block) == key), None)
        if option is None and only_in_domain:
            continue
        for candidate in domain.options:
            model.add_hint(candidate.literal, candidate is option)
        if option is None:
            continue
        rooms: dict[int, str | None] = {}
        if key == search.chosen.get(domain.id):
            rooms = {session.day: session.room_id for session in search.sessions.get(domain.id, [])}
        used = {id(window) for window in option.windows}
        for window in domain.windows.values():
            in_use = id(window) in used
            room_id = rooms.get(window.day) if in_use else None
            if window.rooms and (not in_use or room_id in window.rooms):
                for candidate_room, literal in window.rooms.items():
                    model.add_hint(literal, candidate_room == room_id)
            if window.lab is not None and (not in_use or room_id is not None):
                model.add_hint(window.lab, room_id is not None and data.is_practice_space(room_id))


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
    programar, fuera. Como quitar grupos puede cambiar el primer grupo de un docente, la ocupación de fuera sin
    `campus_code` lleva el campus que le da la entrada completa (`score.py`), y el objetivo del vecindario cuenta SC-09
    como el puntaje con el que se acepta."""
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
    update: dict[str, object] = {"groups": groups}
    if any(slot.campus_code is None for teacher in source.teachers for slot in teacher.busy):
        update["teachers"] = _busy_with_campus(source)

    return source.model_copy(update=update)


def _busy_with_campus(source: SolverInput) -> list[TeacherInput]:
    """Los docentes con el campus de cada ocupación de fuera que no lo trae: el de su primer grupo de la entrada
    completa, o el primer campus, como `SoftScorer`. `BuildSolverInput` siempre lo manda; esto cubre otras entradas."""
    first_campus: dict[str, str] = {}
    for group in source.groups:
        if group.teacher_id is not None:
            first_campus.setdefault(group.teacher_id, group.campus_code)
    default = source.campuses[0].code if source.campuses else None

    return [
        teacher.model_copy(
            update={
                "busy": [
                    slot
                    if slot.campus_code is not None
                    else slot.model_copy(update={"campus_code": first_campus.get(teacher.id, default)})
                    for slot in teacher.busy
                ]
            }
        )
        for teacher in source.teachers
    ]


#: Grupos por vecindario de la mejora: los de unos docentes completos, más los que comparten cohorte con ellos.
IMPROVE_GROUPS = 24

#: Segundos de CP-SAT por vecindario de la mejora.
IMPROVE_STEP_SECONDS = 0.4

#: Hasta cuántos segundos se le dan a un vecindario cuando las vueltas no mejoran (el doble en cada vuelta).
IMPROVE_MAX_STEP_SECONDS = 8.0

#: La parte del tiempo de un vecindario para bajar los niveles, cuando algún grupo puede (PS-10).
LEVEL_SHARE = 0.25

#: Hasta cuántos segundos puede tardar en armarse un vecindario de la mejora (en la carga real, de 0.3 a 0.6 s): uno
#: que tarda más se descarta y no se come el plazo de los demás.
IMPROVE_BUILD_SECONDS = 2.0

#: Segundos para ajustar los salones de un vecindario (SC-03 y SC-06) antes de compararlo.
IMPROVE_ROOM_SECONDS = 0.3


@dataclass
class Improvement:
    """Lo que lleva la mejora: el puntaje al empezar, el mejor, la suma de los niveles del patrón de la mejor
    solución (PS-04), los vecindarios revisados, los aceptados y los que se descartaron porque su modelo pasaba del
    tope de variables (`SearchLimits.max_variables`)."""

    initial: Score
    best: Score
    tiers: int
    steps: int = 0
    accepted: int = 0
    too_large: int = 0
    #: Los aceptados que bajaron la suma de los niveles (PS-10).
    lowered: int = 0


def improve(
    data: ModelData,
    search: Search,
    deadline: float,
    limits: SearchLimits | None = None,
    seed: int = 0,
    report: Callable[[Improvement, float], None] | None = None,
) -> Improvement:
    """La búsqueda por vecindarios con objetivo (7.6), sobre lo que ya programó `lns`.

    Cada vecindario son los grupos programados de unos docentes y los que comparten cohorte con ellos; los demás
    programados entran fijos con sus sesiones, pero siguen en el alcance del objetivo. Sus grupos se programan todos
    (asignación exacta: nunca deja fuera a uno que estaba), cada uno con su nivel del patrón como tope
    (`DomainOptions.keep_tiers`), y el modelo minimiza el objetivo (`objective.py`: las reglas y, como desempate, la
    hora actual) con la pista de lo que tenían.

    Es lexicográfico (PS-10, confirmado el 18 de noviembre de 2026): primero los grupos programados (lo que dejó `lns`,
    que ningún vecindario quita), después la suma de los niveles del patrón y después el puntaje de toda la solución
    (`score.py`, el de PHP). El nivel no va en el objetivo con una escala: con el tope por grupo ninguno sube, así que
    la suma tampoco, y un grupo nunca deja su patrón para bajar el puntaje; si alguno está fuera de su patrón, el
    vecindario primero busca bajar los niveles (`_improve_step`), así que regresa al que ya cabe. Un vecindario se
    acepta si los niveles no suben y el puntaje baja, o si los niveles bajan.

    Cada vecindario tiene `IMPROVE_STEP_SECONDS` de CP-SAT; después de una vuelta sin mejora, el doble (hasta
    `IMPROVE_MAX_STEP_SECONDS`). Para cuando se acaba el plazo o cuando, en una vuelta sin mejora, CP-SAT probó que cada
    vecindario ya era lo mejor. `report(mejora, avance)` se llama después de cada vecindario, con el avance del plazo
    de 0 a 1."""
    limits = limits or SearchLimits()
    scorer = SoftScorer(data.source)
    scope = {group.id for group in data.movable}
    best = scorer.score(search.all_sessions)
    result = Improvement(initial=best, best=best, tiers=sum(search.tiers.values()))
    started = time.perf_counter()
    rng = random.Random(seed)
    step_seconds = IMPROVE_STEP_SECONDS
    while time.perf_counter() < deadline:
        batches = _improvement_batches(data, search, rng)
        improved, proven = False, True
        for movable in batches:
            if time.perf_counter() >= deadline:
                break
            limits.tick()
            result.steps += 1
            step = _improve_step(data, search, movable, scope, deadline, step_seconds, limits, seed + result.steps)
            # Un vecindario que pasa del tope no cambia con más tiempo: cuenta como resuelto.
            proven = proven and (step.proven or step.too_large)
            result.too_large += step.too_large
            if step.sessions is not None:
                sessions = {**search.sessions, **step.sessions}
                proposed = [session for items in sessions.values() for session in items]
                # Los salones del vecindario los repartió la fase B sin ver edificios: se ajustan antes de comparar.
                room_deadline = min(deadline, time.perf_counter() + IMPROVE_ROOM_SECONDS)
                proposed, score = improve_rooms_scored(
                    data,
                    proposed,
                    room_deadline,
                    limits,
                    scope,
                    groups=movable,
                    scorer=scorer,
                    seed=seed + result.steps,
                )
                tiers = sum({**search.tiers, **step.tiers}.values())
                if tiers < result.tiers or (tiers == result.tiers and score.total < result.best.total):
                    result.lowered += tiers < result.tiers
                    search.sessions = defaultdict(list)
                    for session in proposed:
                        search.sessions[session.group_id].append(session)
                    search.sessions = dict(search.sessions)
                    search.chosen.update(step.chosen)
                    search.tiers.update(step.tiers)
                    result.best, result.tiers = score, tiers
                    result.accepted += 1
                    improved = True
            if report is not None:
                elapsed = time.perf_counter() - started
                report(result, min(1.0, elapsed / max(1e-9, deadline - started)))
        if not batches or (not improved and proven):
            # Cada vecindario ya es el mejor que puede ser con lo demás fijo: un óptimo local de estos vecindarios.
            break
        # Una vuelta sin mejora con vecindarios sin resolver del todo: la siguiente les da el doble de tiempo.
        step_seconds = IMPROVE_STEP_SECONDS if improved else min(IMPROVE_MAX_STEP_SECONDS, 2 * step_seconds)

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


@dataclass
class _Step:
    """Lo que dio un vecindario: sus sesiones, opciones y niveles (`None` si no hubo solución a tiempo), si CP-SAT
    probó que es lo mejor del vecindario y si su modelo pasaba del tope de variables."""

    sessions: dict[str, list[ProposedSession]] | None = None
    chosen: dict[str, OptionKey] = field(default_factory=dict)
    tiers: dict[str, int] = field(default_factory=dict)
    proven: bool = False
    too_large: bool = False


def _improve_step(
    data: ModelData,
    search: Search,
    movable: set[str],
    scope: set[str],
    deadline: float,
    step_seconds: float,
    limits: SearchLimits,
    seed: int,
) -> _Step:
    """Un vecindario: sus grupos con el objetivo y lo demás fijo, con `step_seconds` de CP-SAT contados después de
    armarlo (en la carga real, armar uno tarda de 0.3 a 0.6 s, más que el paso de 0.4 s: contado desde antes, CP-SAT se
    quedaba sin tiempo y casi ningún vecindario se resolvía). El armado tiene hasta `IMPROVE_BUILD_SECONDS`: uno que
    tarda más se descarta. Cada grupo lleva los
    niveles hasta el que ya tiene (`keep_tiers`). Es lexicográfico (PS-10): si algún grupo del vecindario está fuera de
    su patrón y tiene en su dominio una opción de un nivel menor, una primera resolución busca la menor suma de niveles
    (así un grupo que ya cabe en su patrón regresa a él), con la cuarta parte del tiempo, y la segunda, con esa suma
    como tope, el menor objetivo. Si los niveles no bajaron, la pista de la segunda sigue siendo lo que había. El tope
    de variables cuenta el dominio y el objetivo: un vecindario que lo pasa se descarta."""
    build_deadline = min(deadline, time.perf_counter() + IMPROVE_BUILD_SECONDS)
    sub = ModelData.of(_sub_input(data.source, search, movable))
    model = cp_model.CpModel()
    options = DomainOptions(
        keep_tiers={group: search.tiers[group] for group in movable if group in search.tiers},
        long_window_rooms=LONG_WINDOW_ROOMS,
    )
    budget = limits.budget(build_deadline)
    try:
        variables = build_variables(model, sub, options, budget)
        if variables.unplaceable():
            return _Step()
        add_hard_constraints(model, variables, sub)
        objective = add_objective(model, variables, sub, scope=scope, budget=budget)
    except BuildTimeout:
        return _Step()
    except ModelTooLarge:
        return _Step(too_large=True)
    step_deadline = min(deadline, time.perf_counter() + step_seconds)
    _add_hints(model, variables, search, sub)
    first: cp_model.CpSolver | None = None
    leveled = True
    # Solo hace falta bajar niveles si algún grupo tiene en su dominio una opción de un nivel menor que el suyo.
    if any(
        option.tier < search.tiers.get(domain.id, 0)
        for domain in variables.groups.values()
        for option in domain.options
    ):
        options_of = [option for domain in variables.groups.values() for option in domain.options]
        tiers = cp_model.LinearExpr.weighted_sum([o.literal for o in options_of], [o.tier for o in options_of])
        current = sum(search.tiers.get(group, 0) for group in variables.groups)
        model.minimize(tiers)
        now = time.perf_counter()
        level, status = solve_until(model, now + (step_deadline - now) * LEVEL_SHARE, limits, seed)
        leveled = status == cp_model.OPTIMAL
        if status in FEASIBLE and round(level.value(tiers)) < current:
            # Bajó el nivel: la pista de las reglas es esa solución solo en los grupos que bajaron; los demás siguen
            # con lo que tenían, que tiene su mismo nivel y mejor puntaje que una solución que solo ve los niveles.
            first = level
            model.add(tiers <= round(level.value(tiers)))
            model.clear_hints()
            hints: dict[str, OptionKey | None] = {}
            for domain in variables.groups.values():
                lowered = chosen_option(first, domain)
                keep = lowered is None or lowered.tier >= search.tiers.get(domain.id, 0)
                hints[domain.id] = search.chosen.get(domain.id) if keep else (lowered.spread, lowered.block)
            _add_hints(model, variables, search, sub, chosen=hints)
        # Si no bajó, o si no alcanzó el tiempo (UNKNOWN), sigue con las reglas: el tope de cada grupo
        # (`keep_tiers`) ya impide que el nivel suba, y la pista es lo que había.
    model.minimize(objective.expression())
    solver, status = solve_until(model, step_deadline, limits, seed)
    if status not in FEASIBLE:
        if first is None:
            return _Step()
        # Sin tiempo para el objetivo: se queda con la solución de la primera resolución, que ya baja el nivel.
        solver, status = first, cp_model.FEASIBLE
    try:
        proposed = sessions_of(solver, variables, sub)
    except RoomAssignmentError:
        return _Step()
    step = _Step(sessions={group: [] for group in variables.groups}, proven=leveled and status == cp_model.OPTIMAL)
    for session in proposed:
        step.sessions[session.group_id].append(session)
    for domain in variables.groups.values():
        option = chosen_option(solver, domain)
        if option is None:
            return _Step()
        step.chosen[domain.id] = (option.spread, option.block)
        step.tiers[domain.id] = option.tier

    return step
