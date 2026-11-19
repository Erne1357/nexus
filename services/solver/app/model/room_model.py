"""La fase B con objetivo: con el horario ya fijo, un modelo chico por día que vuelve a elegir los salones para bajar
SC-03 (salón por defecto) y SC-06 (cambio de edificio) (tarea 7.6; docs/arquitectura/solver.md, «Modelo CP-SAT:
objetivo»).

El emparejamiento de la fase B (`rooms.match`) da salones sin choques; este modelo parte de ellos (son su pista y su
respaldo) y solo mueve las ventanas que pueden cambiar el puntaje: las de grupos con salón por defecto y las que están
pegadas a otra sesión del docente con salón. Las demás se quedan donde están y ocupan su salón.

- Cada ventana (las sesiones de un grupo en un día, que van en un mismo salón, PS-05) toma uno de sus candidatos: el
  salón que ya tiene, los por defecto de su materia y los más ajustados de los edificios de las sesiones pegadas del
  docente. Todos le sirven como en el dominio: su campus y sus candidatos, la capacidad (HC-05), sin bloqueo (HC-11),
  sin ocupación fija (HC-02), propio o prestado (HC-10) y, si lleva práctica, espacio de práctica (HC-06).
- Un salón no tiene dos ventanas que se empalman (HC-02).
- Los días no se tocan entre sí: cada uno es un modelo aparte.
- SC-03 cuenta las horas fuera de los salones por defecto; SC-06, un cambio de edificio por par de sesiones pegadas del
  docente. Desempatan (por debajo de las reglas, como en `objective.py`) el salón de `current_sessions` (PS-07) y la
  teoría en aula (PS-05).
- El tope de variables (`SearchLimits.max_variables`) vale para el modelo de cada día: un día que lo pasa se queda con
  los salones que tenía.
"""

import time
from collections import defaultdict
from dataclasses import dataclass, field

from ortools.sat.python import cp_model

from app.contract.input import SessionKind
from app.contract.output import ProposedSession

from .data import ModelData
from .pattern import minutes
from .phases import FEASIBLE, SearchLimits, run_solver
from .score import Score, SoftScorer, hours
from .variables import ModelTooLarge

#: Salones por edificio que se ofrecen a una ventana pegada a una sesión de ese edificio: los más ajustados.
ROOMS_PER_BUILDING = 4


@dataclass(eq=False)
class _Window:
    group_id: str
    teacher_id: str | None
    day: int
    start: int
    end: int
    room_id: str
    practice: bool
    sessions: list[int]
    #: Las horas de SC-03 de sus sesiones (`Timeline::hours` de cada una).
    hours: int
    candidates: list[str] = field(default_factory=list)
    choice: dict[str, cp_model.IntVar] = field(default_factory=dict)


def improve_rooms(
    data: ModelData,
    sessions: list[ProposedSession],
    deadline: float,
    limits: SearchLimits | None = None,
    scope: set[str] | None = None,
    groups: set[str] | None = None,
) -> list[ProposedSession]:
    """Las mismas sesiones con los salones que bajan SC-03 y SC-06; con `groups`, solo cambian los salones de esos
    grupos. Si no hay nada que ganar, no alcanza el tiempo o el modelo no mejora, las de entrada."""
    return improve_rooms_scored(data, sessions, deadline, limits, scope, groups)[0]


def improve_rooms_scored(
    data: ModelData,
    sessions: list[ProposedSession],
    deadline: float,
    limits: SearchLimits | None = None,
    scope: set[str] | None = None,
    groups: set[str] | None = None,
    scorer: SoftScorer | None = None,
) -> tuple[list[ProposedSession], Score]:
    """`improve_rooms` con el puntaje de lo que devuelve (de `score.py`, con `scorer` si ya se tiene uno)."""
    limits = limits or SearchLimits()
    scorer = scorer or SoftScorer(data.source)
    weights = scorer.weights
    if not (weights["SC-03"] or weights["SC-06"]):
        return sessions, scorer.score(sessions)
    scope = {group.id for group in data.movable} if scope is None else scope
    windows, loose = _windows(data, sessions)
    involved = _involved(data, scorer, windows, scope)
    if groups is not None:
        involved = {window for window in involved if window.group_id in groups}
    if not involved:
        return sessions, scorer.score(sessions)

    by_day: dict[int, list[_Window]] = defaultdict(list)
    for window in windows:
        by_day[window.day].append(window)
    rooms = {window: window.room_id for window in windows}
    days = sorted({window.day for window in involved})
    for index, day in enumerate(days):
        now = time.perf_counter()
        if now >= deadline:
            break
        share = (deadline - now) / (len(days) - index)
        day_loose = [(room_id, start, end) for room_id, loose_day, start, end in loose if loose_day == day]
        try:
            rooms.update(_solve_day(data, scorer, by_day[day], day_loose, involved, scope, groups, now + share, limits))
        except ModelTooLarge:
            # Un día que pasa del tope de variables se queda con los salones del emparejamiento.
            continue

    room_of = {index: rooms[window] for window in windows for index in window.sessions}
    improved = [
        session.model_copy(update={"room_id": room_of[index]})
        if room_of.get(index, session.room_id) != session.room_id
        else session
        for index, session in enumerate(sessions)
    ]
    before = scorer.score(sessions)
    if all(new is old for new, old in zip(improved, sessions, strict=True)):
        # Ningún salón cambió: un solo puntaje (en la mejora corre una vez por vecindario sobre toda la solución).
        return sessions, before
    after = scorer.score(improved)

    return (improved, after) if after.total <= before.total else (sessions, before)


def _windows(data: ModelData, sessions: list[ProposedSession]) -> tuple[list[_Window], list[tuple[str, int, int, int]]]:
    """Las sesiones con salón de cada grupo y día, como ventanas. Un día de un grupo con dos salones no es una
    ventana del modelo: se queda como está y ocupa sus salones (salón, día, inicio, fin), para que nadie más los tome
    a esa hora (HC-02)."""
    grouped: dict[tuple[str, int], list[int]] = defaultdict(list)
    for index, session in enumerate(sessions):
        if session.room_id is not None:
            grouped[(session.group_id, session.day)].append(index)
    windows = []
    loose: list[tuple[str, int, int, int]] = []
    for (group_id, day), indexes in grouped.items():
        group = data.groups[group_id]
        rooms = {sessions[i].room_id for i in indexes}
        if len(rooms) != 1:
            loose.extend(
                (sessions[i].room_id, day, minutes(sessions[i].starts_at), minutes(sessions[i].ends_at))
                for i in indexes
            )
            continue
        windows.append(
            _Window(
                group_id=group_id,
                teacher_id=group.teacher_id,
                day=day,
                start=min(minutes(sessions[i].starts_at) for i in indexes),
                end=max(minutes(sessions[i].ends_at) for i in indexes),
                room_id=rooms.pop(),
                practice=group.needs_lab and any(sessions[i].kind == SessionKind.PRACTICE for i in indexes),
                sessions=indexes,
                hours=sum(hours(minutes(sessions[i].ends_at) - minutes(sessions[i].starts_at)) for i in indexes),
            )
        )

    return windows, loose


def _involved(data: ModelData, scorer: SoftScorer, windows: list[_Window], scope: set[str]) -> set[_Window]:
    """Las ventanas cuyo salón cambia el puntaje: con salón por defecto (SC-03) o pegadas a otra sesión con salón del
    docente (SC-06)."""
    involved = set()
    if scorer.weights["SC-03"]:
        for window in windows:
            group = data.groups[window.group_id]
            if window.group_id in scope and scorer.defaults.get((group.subject_code, group.campus_code)):
                involved.add(window)
    if scorer.weights["SC-06"]:
        for window, _ in _adjacent_pairs(data, scorer, windows):
            involved.add(window)

    return involved


def _adjacent_pairs(data: ModelData, scorer: SoftScorer, windows: list[_Window]) -> list[tuple[_Window, object]]:
    """Cada ventana con lo que tiene pegado del mismo docente con salón: otra ventana o el edificio de una sesión
    fija."""
    by_teacher: dict[tuple[str, int], list[_Window]] = defaultdict(list)
    for window in windows:
        if window.teacher_id is not None:
            by_teacher[(window.teacher_id, window.day)].append(window)
    fixed: dict[tuple[str, int], list[tuple[int, int, str]]] = defaultdict(list)
    for group in data.source.groups:
        if group.movable or group.teacher_id is None:
            continue
        for session in scorer.fixed[group.id]:
            building = scorer.buildings.get(session.room_id) if session.room_id is not None else None
            if building is not None:
                fixed[(group.teacher_id, session.day)].append((session.start, session.end, building))
    pairs: list[tuple[_Window, object]] = []
    for key, day_windows in by_teacher.items():
        for window in day_windows:
            for other in day_windows:
                if other is not window and (other.end == window.start or other.start == window.end):
                    pairs.append((window, other))
            for start, end, building in fixed.get(key, []):
                if end == window.start or start == window.end:
                    pairs.append((window, building))

    return pairs


def _solve_day(
    data: ModelData,
    scorer: SoftScorer,
    windows: list[_Window],
    loose: list[tuple[str, int, int]],
    involved: set[_Window],
    scope: set[str],
    groups: set[str] | None,
    deadline: float,
    limits: SearchLimits,
) -> dict[_Window, str]:
    """Un día: las ventanas del modelo y las que les estorban eligen salón; las demás y `loose` ocupan el suyo. Con
    `groups`, solo se mueven las de esos grupos."""
    pairs = _adjacent_pairs(data, scorer, windows)
    buildings_near: dict[_Window, set[str]] = defaultdict(set)
    for window, other in pairs:
        if isinstance(other, _Window):
            buildings_near[window].add(scorer.buildings[other.room_id])
        else:
            buildings_near[window].add(other)
    # Las ventanas que ocupan, a la misma hora, un salón que una ventana del modelo querría (por defecto o de un
    # edificio cercano) también se mueven: así dos grupos pueden cambiar de salón.
    fitting: dict[_Window, list[str]] = {}

    def candidates(window: _Window, taken: dict[str, list[tuple[int, int]]]) -> list[str]:
        if window not in fitting:
            fitting[window] = _fitting(data, window)
        return _candidates(data, scorer, window, buildings_near[window], taken, fitting[window])

    wanted: dict[str, list[_Window]] = defaultdict(list)
    for window in windows:
        if window in involved:
            for room_id in candidates(window, {}):
                wanted[room_id].append(window)
    blockers = {
        window
        for window in windows
        if window not in involved
        and (groups is None or window.group_id in groups)
        and any(other.start < window.end and window.start < other.end for other in wanted.get(window.room_id, []))
    }
    moving = [window for window in windows if window in involved or window in blockers]
    taken: dict[str, list[tuple[int, int]]] = defaultdict(list)
    for window in windows:
        if window not in involved and window not in blockers:
            taken[window.room_id].append((window.start, window.end))
    for room_id, start, end in loose:
        taken[room_id].append((start, end))

    model = cp_model.CpModel()
    budget = limits.budget(deadline)
    for window in moving:
        window.candidates = candidates(window, taken)
        window.choice = {room_id: model.new_bool_var("") for room_id in window.candidates}
        model.add_exactly_one(window.choice.values())
        for room_id, literal in window.choice.items():
            model.add_hint(literal, room_id == window.room_id)
        budget.check(len(model.proto.variables), f"los salones del día {window.day} (fase B)")
    if data.active("HC-02"):
        _no_overlap(model, moving)

    penalty: list[tuple[int, cp_model.LinearExprT]] = []
    preference: list[tuple[int, cp_model.LinearExprT]] = []
    current = _current_rooms(data, moving)
    for window in moving:
        group = data.groups[window.group_id]
        defaults = scorer.defaults.get((group.subject_code, group.campus_code), set())
        for room_id, literal in window.choice.items():
            if scorer.weights["SC-03"] and defaults and window.group_id in scope and room_id not in defaults:
                penalty.append((scorer.weights["SC-03"] * window.hours, literal))
            preferred = current.get(window)
            cost = int(preferred is not None and room_id != preferred) + int(
                not window.practice and data.is_practice_space(room_id)
            )
            if cost:
                preference.append((cost, literal))
    if scorer.weights["SC-06"]:
        in_model = set(moving)
        for window, other in pairs:
            # Un par de ventanas del modelo sale dos veces: se cuenta una, en un orden que no cambia entre corridas.
            if window not in involved or (
                isinstance(other, _Window) and other in involved and _key(other) < _key(window)
            ):
                continue
            change = model.new_bool_var("")
            for building in sorted({scorer.buildings[room_id] for room_id in window.candidates}):
                mine = [literal for room_id, literal in window.choice.items() if scorer.buildings[room_id] == building]
                if isinstance(other, _Window) and other in in_model:
                    # La otra ventana también elige salón (del modelo o una que le estorba): su edificio no es fijo.
                    theirs = [lit for room, lit in other.choice.items() if scorer.buildings[room] == building]
                    model.add(change >= sum(mine) - sum(theirs))
                else:
                    target = scorer.buildings[other.room_id] if isinstance(other, _Window) else other
                    if target != building:
                        model.add(change >= sum(mine))
            penalty.append((scorer.weights["SC-06"], change))
            budget.check(len(model.proto.variables), f"los salones del día {window.day} (fase B)")
    if not penalty:
        return {}
    scale = sum(cost for cost, _ in preference) + 1
    model.minimize(
        cp_model.LinearExpr.weighted_sum([t for _, t in penalty], [scale * c for c, _ in penalty])
        + cp_model.LinearExpr.weighted_sum([t for _, t in preference], [c for c, _ in preference])
    )
    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = max(0.05, deadline - time.perf_counter())
    if run_solver(solver, model, limits) not in FEASIBLE:
        return {}

    return {
        window: next(room_id for room_id, literal in window.choice.items() if solver.boolean_value(literal))
        for window in moving
    }


def _key(window: _Window) -> tuple[str, int, int]:
    return (window.group_id, window.day, window.start)


def _fitting(data: ModelData, window: _Window) -> list[str]:
    """Los salones que le sirven a la ventana, sin contar lo que ocupan las demás ventanas: su campus y sus
    candidatos, la capacidad (HC-05), la práctica (HC-06), sin bloqueo, sin ocupación fija y propio o prestado
    (`room_reason`); del más chico al más grande. Se calcula una vez por ventana y día."""
    group = data.groups[window.group_id]
    allowed = set(group.candidate_room_ids)

    def fits(room_id: str) -> bool:
        room = data.rooms[room_id]
        return (
            room.campus_code == group.campus_code
            and (not allowed or room_id in allowed)
            and (not data.active("HC-05") or room.capacity >= group.capacity)
            and (not window.practice or not data.active("HC-06") or data.is_practice_space(room_id))
            and data.room_reason(room_id, group.department_id, window.day, window.start, window.end, group.id) is None
        )

    return sorted(
        (room_id for room_id in data.rooms_by_campus.get(group.campus_code, []) if fits(room_id)),
        key=lambda room_id: (data.rooms[room_id].capacity, room_id),
    )


def _candidates(
    data: ModelData,
    scorer: SoftScorer,
    window: _Window,
    near: set[str],
    taken: dict[str, list[tuple[int, int]]],
    fitting: list[str],
) -> list[str]:
    """El salón actual, los por defecto de la materia y los más ajustados de los edificios cercanos, de los que le
    sirven a la ventana (`_fitting`) y nadie más ocupa a esa hora (HC-02)."""
    group = data.groups[window.group_id]
    defaults = scorer.defaults.get((group.subject_code, group.campus_code), set())
    if data.active("HC-02"):
        fitting = [
            room_id
            for room_id in fitting
            if not any(start < window.end and window.start < end for start, end in taken.get(room_id, []))
        ]
    chosen = {window.room_id: None}
    chosen.update(dict.fromkeys(room_id for room_id in fitting if room_id in defaults))
    for building in sorted(near | {scorer.buildings[window.room_id]}):
        in_building = [room_id for room_id in fitting if scorer.buildings[room_id] == building]
        chosen.update(dict.fromkeys(in_building[:ROOMS_PER_BUILDING]))

    return list(chosen)


def _no_overlap(model: cp_model.CpModel, windows: list[_Window]) -> None:
    """A lo más una ventana por salón en cada inicio de ventana (HC-02)."""
    by_room: dict[str, list[tuple[_Window, cp_model.IntVar]]] = defaultdict(list)
    for window in windows:
        for room_id, literal in window.choice.items():
            by_room[room_id].append((window, literal))
    for items in by_room.values():
        for window, _ in items:
            covering = [literal for other, literal in items if other.start <= window.start < other.end]
            if len(covering) > 1:
                model.add_at_most_one(covering)


def _current_rooms(data: ModelData, windows: list[_Window]) -> dict[_Window, str]:
    """El salón que el grupo ya tenía ese día a esas horas (`current_sessions`), si lo tenía (PS-07)."""
    found = {}
    for window in windows:
        for slot in data.groups[window.group_id].current_sessions:
            if slot.day == window.day and slot.room_id is not None and minutes(slot.starts_at) == window.start:
                found[window] = slot.room_id
                break

    return found
