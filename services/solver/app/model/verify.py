"""Un verificador mínimo de las reglas duras sobre una salida del contrato, en Python.

Revisa las sesiones propuestas de los grupos movibles contra la entrada con la definición del evaluador en PHP
(`Scheduling/Rules/Constraints/*` sobre `Solver/Evaluation/ContractState`), regla por regla y solo las activas
(`hard_rules`): empalmes en minutos con intervalos medio abiertos, la ocupación de fuera de la corrida y de los grupos
fijos como contexto, y cada choque entre dos grupos una sola vez con los dos. Sirve para probar el modelo CP-SAT (7.5)
y los fixtures compartidos (7.10); la autoridad sigue siendo el evaluador en PHP, por el que pasarán también las
salidas de CP-SAT en la 7.10.

Es independiente del modelo: lee solo el contrato (`SolverInput` y las sesiones de `SolverOutput`) y no importa nada
de `app/model`, para que un error que el modelo y sus datos compartan (préstamos, alineación, horas del docente) no se
le escape.

Diferencias a propósito: HC-03 sigue la regla del contrato (dos grupos con una misma clave de cohorte no se empalman),
más estricta que la combinación del alumno tipo del evaluador; HC-07 y HC-13 no se pueden revisar con el contrato, como
en el evaluador. Tampoco revisa la forma de la salida: una sesión sin salón, o con uno que no está en `rooms`, se salta
las reglas de salón (como en el evaluador, donde una sesión sin salón no tiene dónde fallar); que cada sesión lleve un
salón de su campus lo exigen las pruebas de fixtures, como `SolverFixturesTest` en PHP.
"""

from collections import defaultdict
from dataclasses import dataclass

from app.contract.input import GroupInput, SessionKind, SolverInput, TimeSlot
from app.contract.output import HardViolation, ProposedSession

#: Los tipos de salón que son espacio de práctica (HC-06, `RoomType::isPracticeSpace`).
_PRACTICE_SPACES = frozenset({"laboratorio", "taller", "sala_computo"})


@dataclass(frozen=True)
class _Busy:
    """Una sesión ocupando un recurso: de un grupo de la entrada (`group_id`) o de fuera de la corrida (`None`)."""

    group_id: str | None
    movable: bool
    day: int
    start: int
    end: int


def hard_violations(data: SolverInput, sessions: list[ProposedSession]) -> list[HardViolation]:
    """Las violaciones duras de las sesiones propuestas, de las reglas activas de la entrada."""
    rules = set(data.hard_rules)
    violations: list[HardViolation] = []

    by_teacher: dict[str, list[_Busy]] = defaultdict(list)
    by_room: dict[str, list[_Busy]] = defaultdict(list)
    by_cohort: dict[str, list[_Busy]] = defaultdict(list)
    for teacher in data.teachers:
        by_teacher[teacher.id].extend(_Busy(None, False, slot.day, *_span(slot)) for slot in teacher.busy)
    for room in data.rooms:
        by_room[room.id].extend(_Busy(None, False, slot.day, *_span(slot)) for slot in room.busy)

    proposed: dict[str, list[ProposedSession]] = defaultdict(list)
    for session in sessions:
        proposed[session.group_id].append(session)
    for group in data.groups:
        for slot in proposed[group.id] if group.movable else group.fixed_sessions:
            busy = _Busy(group.id, group.movable, slot.day, _minutes(slot.starts_at), _minutes(slot.ends_at))
            if group.teacher_id is not None:
                by_teacher[group.teacher_id].append(busy)
            if slot.room_id is not None:
                by_room[slot.room_id].append(busy)
            for key in sorted(set(group.cohorts)):
                by_cohort[key].append(busy)

    if "HC-01" in rules:
        for teacher_id, busy in by_teacher.items():
            violations.extend(
                HardViolation(
                    rule="HC-01", group_ids=ids, teacher_id=teacher_id, message=f"El docente ya da clase el {when}."
                )
                for ids, when in _overlapping(busy)
            )
    if "HC-02" in rules:
        for room_id, busy in by_room.items():
            violations.extend(
                HardViolation(
                    rule="HC-02", group_ids=ids, room_id=room_id, message=f"El salón ya está ocupado el {when}."
                )
                for ids, when in _overlapping(busy)
            )
    if "HC-03" in rules:
        for key, busy in by_cohort.items():
            violations.extend(
                HardViolation(rule="HC-03", group_ids=ids, message=f"Se empalma con su cohorte {key} el {when}.")
                for ids, when in _overlapping(busy)
            )

    for group in data.groups:
        if group.movable:
            violations.extend(_group_violations(data, rules, group, proposed[group.id]))
    if "HC-08" in rules:
        violations.extend(_teacher_hours(data))

    return violations


def _overlapping(busy: list[_Busy]) -> list[tuple[list[str], str]]:
    """Los pares de sesiones de grupos distintos que se empalman y tocan a un grupo movible, una vez por par de grupos
    y día y hora, con los grupos de la entrada que chocan."""
    found: dict[tuple[str, ...], str] = {}
    ordered = sorted(busy, key=lambda item: (item.day, item.start))
    for i, first in enumerate(ordered):
        for second in ordered[i + 1 :]:
            # En orden de día e inicio: las que siguen empiezan después de que esta termina.
            if second.day != first.day or second.start >= first.end:
                break
            if first.group_id == second.group_id or not (first.movable or second.movable):
                continue
            ids = sorted({item.group_id for item in (first, second) if item.group_id is not None})
            when = f"día {second.day} a las {second.start // 60:02d}:{second.start % 60:02d}"
            found.setdefault((*ids, when), when)

    return [(list(key[:-1]), when) for key, when in found.items()]


def _group_violations(
    data: SolverInput, rules: set[str], group: GroupInput, sessions: list[ProposedSession]
) -> list[HardViolation]:
    campus = next(campus for campus in data.campuses if campus.code == group.campus_code)
    day_start, day_end = _minutes(campus.day_start), _minutes(campus.day_end)
    teacher = next((teacher for teacher in data.teachers if teacher.id == group.teacher_id), None)
    rooms = {room.id: room for room in data.rooms}
    violations: list[HardViolation] = []

    def add(rule: str, message: str, room_id: str | None = None, teacher_id: str | None = None) -> None:
        if rule in rules:
            violations.append(
                HardViolation(rule=rule, group_ids=[group.id], room_id=room_id, teacher_id=teacher_id, message=message)
            )

    for session in sessions:
        start, end = _minutes(session.starts_at), _minutes(session.ends_at)
        when = f"día {session.day} de {session.starts_at} a {session.ends_at}"
        if (
            session.day not in campus.days
            or start < day_start
            or end > day_end
            or (start - day_start) % campus.block_minutes
            or (end - day_start) % campus.block_minutes
        ):
            add("HC-04", f"La sesión del {when} cae fuera de la rejilla de {campus.code}.")
        if teacher is not None and _overlaps(teacher.unavailable, session.day, start, end):
            add("HC-09", f"El docente no está disponible el {when}.", teacher_id=teacher.id)
        room = rooms.get(session.room_id) if session.room_id is not None else None
        if room is None:
            continue
        if room.capacity < group.capacity:
            add("HC-05", f"El salón tiene {room.capacity} lugares y el grupo {group.capacity}.", room_id=room.id)
        if group.needs_lab and session.kind == SessionKind.PRACTICE and room.type not in _PRACTICE_SPACES:
            add("HC-06", f"La práctica del {when} necesita laboratorio, taller o sala de cómputo.", room_id=room.id)
        if room.owner_department_id is not None and room.owner_department_id != group.department_id:
            loans = [
                (_minutes(loan.starts_at), _minutes(loan.ends_at))
                for loan in data.agreements
                if loan.room_id == room.id and loan.to_department_id == group.department_id and loan.day == session.day
            ]
            if not _covered(loans, start, end):
                add("HC-10", f"El salón es de otro departamento y no hay préstamo para el {when}.", room_id=room.id)
        if _overlaps(room.blocks, session.day, start, end):
            add("HC-11", f"El salón está bloqueado el {when}.", room_id=room.id)

    if sessions:
        scheduled = sum(_minutes(session.ends_at) - _minutes(session.starts_at) for session in sessions)
        required = group.weekly_hours * 60
        if abs(scheduled - required) > data.weekly_hours_tolerance:
            add("HC-12", f"Tiene {scheduled} min de los {required} semanales.")

    return violations


def _teacher_hours(data: SolverInput) -> list[HardViolation]:
    """HC-08 (`TeacherHours` sobre `ContractState`): el límite del docente es `remaining_hours` más las horas de sus
    grupos fijos y lleva las de todos sus grupos; si se pasa, una violación en cada grupo movible suyo."""
    violations = []
    for teacher in data.teachers:
        groups = [group for group in data.groups if group.teacher_id == teacher.id]
        fixed = sum(group.weekly_hours for group in groups if not group.movable)
        total = sum(group.weekly_hours for group in groups)
        limit = teacher.remaining_hours + fixed
        if total <= limit:
            continue
        message = f"El docente suma {total} h y su límite es {limit} h: se pasa por {total - limit} h."
        violations.extend(
            HardViolation(rule="HC-08", group_ids=[group.id], teacher_id=teacher.id, message=message)
            for group in groups
            if group.movable
        )

    return violations


def _minutes(clock: str) -> int:
    return int(clock[:2]) * 60 + int(clock[3:])


def _span(slot: TimeSlot) -> tuple[int, int]:
    return _minutes(slot.starts_at), _minutes(slot.ends_at)


def _overlaps(slots: list[TimeSlot], day: int, start: int, end: int) -> bool:
    return any(slot.day == day and start < _minutes(slot.ends_at) and _minutes(slot.starts_at) < end for slot in slots)


def _covered(ranges: list[tuple[int, int]], start: int, end: int) -> bool:
    """Si la unión de los rangos cubre todo [start, end), como `RoomLoan::covers`."""
    reached = start
    for range_start, range_end in sorted(ranges):
        if range_start > reached:
            break
        reached = max(reached, range_end)

    return reached >= end
