"""Las sesiones de una solución del modelo CP-SAT, en el contrato (`ProposedSession`).

Cada grupo movible con opción elegida da una sesión por unidad de cada ventana, con el salón de la ventana. El tipo
sigue a la heurística (`Builder::slots`, `practiceDays`, `chooseRooms`): sin salones, `virtual`; las unidades de
práctica de una materia con laboratorio van en las ventanas que quedaron en laboratorio, taller o sala de cómputo,
primero en los días donde ya tenía práctica y luego en los más tempranos; sin laboratorio, en los días donde ya tenía
práctica y luego en los últimos. `changed` dice si la sesión no estaba igual en `current_sessions` (PS-07).
"""

from ortools.sat.python import cp_model

from app.contract.input import SessionKind
from app.contract.output import ProposedSession

from .data import ModelData
from .pattern import clock, minutes
from .variables import GroupDomain, Option, Variables


def chosen_option(solver: cp_model.CpSolver, domain: GroupDomain) -> Option | None:
    return next((option for option in domain.options if solver.boolean_value(option.literal)), None)


def sessions_of(solver: cp_model.CpSolver, variables: Variables, data: ModelData) -> list[ProposedSession]:
    sessions: list[ProposedSession] = []
    for domain in variables.groups.values():
        option = chosen_option(solver, domain)
        if option is not None:
            sessions.extend(_group_sessions(solver, domain, option, data))

    return sessions


def _group_sessions(
    solver: cp_model.CpSolver, domain: GroupDomain, option: Option, data: ModelData
) -> list[ProposedSession]:
    grid = domain.grid
    group = domain.group
    rooms = {
        window.day: next((room_id for room_id, literal in window.rooms.items() if solver.boolean_value(literal)), None)
        for window in option.windows
    }
    practice = _practice_by_day(domain, option, rooms, data)
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
    domain: GroupDomain, option: Option, rooms: dict[int, str | None], data: ModelData
) -> dict[int, int]:
    """Las unidades de práctica de cada día de la opción."""
    group = domain.group
    grid = domain.grid
    if not grid.uses_rooms or group.practice_hours == 0:
        return {}

    units = {window.day: window.units for window in option.windows}
    left = min(sum(units.values()), int(group.practice_hours * 60 / grid.unit_minutes + 0.5))
    had_practice = {slot.day for slot in group.current_sessions if slot.kind == SessionKind.PRACTICE}
    if domain.lab_practice:
        days = sorted(
            (day for day, room_id in rooms.items() if room_id is not None and data.is_practice_space(room_id)),
            key=lambda day: (day not in had_practice, day),
        )
    else:
        days = sorted(units, key=lambda day: (day not in had_practice, -day))

    practice = {}
    for day in days:
        practice[day] = min(units[day], left)
        left -= practice[day]

    return practice
