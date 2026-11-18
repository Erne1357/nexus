"""Las sesiones de una solución del modelo CP-SAT, en el contrato (`ProposedSession`).

Cada grupo movible con opción elegida da una sesión por unidad de cada ventana, con el salón de la ventana. El tipo
sigue a la heurística (`Builder::slots`, `practiceDays`, `chooseRooms`): sin salones, `virtual`; las unidades de
práctica de una materia con laboratorio van en las ventanas que quedaron en laboratorio, taller o sala de cómputo,
primero en los días donde ya tenía práctica y luego en los más tempranos; sin laboratorio, en los días donde ya tenía
práctica y luego en los últimos. `changed` dice si la sesión no estaba igual en `current_sessions` (PS-07).

El salón de una ventana con `z` es el que eligió el modelo; el de una ventana del conjunto de salones lo reparte la
fase B (`assign_rooms`, `rooms.match`).
"""

from ortools.sat.python import cp_model

from app.contract.input import SessionKind
from app.contract.output import ProposedSession

from .data import ModelData
from .pattern import clock, minutes
from .rooms import PooledSession, match
from .variables import GroupDomain, Option, Variables, Window, practice_units

#: El salón de cada ventana del conjunto: (grupo, día) -> salón.
PooledRooms = dict[tuple[str, int], str]


def chosen_option(solver: cp_model.CpSolver, domain: GroupDomain) -> Option | None:
    return next((option for option in domain.options if solver.boolean_value(option.literal)), None)


def sessions_of(solver: cp_model.CpSolver, variables: Variables, data: ModelData) -> list[ProposedSession]:
    pooled = assign_rooms(solver, variables, data)
    sessions: list[ProposedSession] = []
    for domain in variables.groups.values():
        option = chosen_option(solver, domain)
        if option is not None:
            sessions.extend(_group_sessions(solver, domain, option, data, pooled))

    return sessions


def assign_rooms(solver: cp_model.CpSolver, variables: Variables, data: ModelData) -> PooledRooms:
    """La fase B: el salón de cada ventana elegida del conjunto, bloque por bloque, sin los salones que tomaron las
    ventanas explícitas en ese bloque. Lanza `RoomAssignmentError` si un bloque no se puede repartir, lo que la fase A
    impide."""
    taken: dict[tuple[str, int, int], set[str]] = {}
    for domain in variables.groups.values():
        span = domain.grid.blocks_per_unit
        for window in domain.windows.values():
            room = _explicit_room(solver, window)
            if room is None:
                continue
            for block in range(window.block, window.block + window.units * span):
                taken.setdefault((domain.group.campus_code, window.day, block), set()).add(room)

    assigned: PooledRooms = {}
    for (campus, day, block), windows in variables.pooled.items():
        sessions = [
            PooledSession(
                window.group_id,
                day,
                variables.groups[window.group_id].threshold,
                window.lab is not None and solver.boolean_value(window.lab),
            )
            for window in windows
            if _uses(solver, window)
        ]
        if not sessions:
            continue
        rooms = match(
            variables.pools[campus], day, block, sessions, taken.get((campus, day, block), set()), data.active("HC-02")
        )
        for session, room in rooms.items():
            assigned[(session.group_id, day)] = room

    return assigned


def _uses(solver: cp_model.CpSolver, window: Window) -> bool:
    return any(solver.boolean_value(literal) for literal in window.literals)


def _explicit_room(solver: cp_model.CpSolver, window: Window) -> str | None:
    return next((room_id for room_id, literal in window.rooms.items() if solver.boolean_value(literal)), None)


def _group_sessions(
    solver: cp_model.CpSolver, domain: GroupDomain, option: Option, data: ModelData, pooled: PooledRooms
) -> list[ProposedSession]:
    grid = domain.grid
    group = domain.group
    rooms = {
        window.day: pooled.get((group.id, window.day)) if window.pooled else _explicit_room(solver, window)
        for window in option.windows
    }
    lab_days = {
        window.day
        for window in option.windows
        if (window.lab is not None and solver.boolean_value(window.lab))
        or (window.rooms and all(data.is_practice_space(room_id) for room_id in window.rooms))
    }
    practice = _practice_by_day(domain, option, rooms, lab_days, data)
    current = {
        (slot.day, minutes(slot.starts_at), minutes(slot.ends_at), slot.room_id, slot.kind)
        for slot in group.current_sessions
    }

    sessions = []
    for window in option.windows:
        for unit in range(window.units):
            start = window.start + unit * grid.unit_minutes
            end = start + grid.unit_minutes
            if not grid.uses_rooms:
                kind = SessionKind.VIRTUAL
            else:
                kind = SessionKind.PRACTICE if unit < practice.get(window.day, 0) else SessionKind.THEORY
            room_id = rooms[window.day]
            sessions.append(
                ProposedSession(
                    group_id=group.id,
                    day=window.day,
                    starts_at=clock(start),
                    ends_at=clock(end),
                    room_id=room_id,
                    kind=kind,
                    changed=(window.day, start, end, room_id, kind) not in current,
                )
            )

    return sessions


def _practice_by_day(
    domain: GroupDomain, option: Option, rooms: dict[int, str | None], lab_days: set[int], data: ModelData
) -> dict[int, int]:
    """Las unidades de práctica de cada día de la opción.

    Con laboratorio, los días cuyo salón es un espacio de práctica, como `chooseRooms`: primero los que el modelo puso
    en laboratorio (`lab_days`: la fase A los contó como práctica, o todos los salones de su ventana son espacios de
    práctica), luego los que ya tenían práctica y luego los más tempranos. Sin laboratorio, como `practiceDays`.
    """
    group = domain.group
    grid = domain.grid
    if not grid.uses_rooms or group.practice_hours == 0:
        return {}

    units = {window.day: window.units for window in option.windows}
    had_practice = {slot.day for slot in group.current_sessions if slot.kind == SessionKind.PRACTICE}
    if domain.lab_practice:
        left = option.practice
        days = sorted(
            (day for day, room_id in rooms.items() if room_id is not None and data.is_practice_space(room_id)),
            key=lambda day: (day not in lab_days, day not in had_practice, day),
        )
    else:
        left = practice_units(group, grid, sum(units.values()))
        days = sorted(units, key=lambda day: (day not in had_practice, -day))

    practice = {}
    for day in days:
        practice[day] = min(units[day], left)
        left -= practice[day]

    return practice
