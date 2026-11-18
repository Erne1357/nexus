"""Un verificador mínimo de las reglas duras sobre una salida del contrato, en Python.

Revisa las sesiones propuestas de los grupos movibles contra la entrada con la definición del evaluador en PHP
(`Scheduling/Rules/Constraints/*` sobre `Solver/Evaluation/ContractState`), regla por regla y solo las activas
(`hard_rules`): empalmes en minutos con intervalos medio abiertos, la ocupación de fuera de la corrida y de los grupos
fijos como contexto, y cada choque entre dos grupos una sola vez con los dos. Sirve para probar el modelo CP-SAT (7.5)
y los fixtures compartidos (7.10); la autoridad sigue siendo el evaluador en PHP.

Diferencias a propósito: HC-03 sigue la regla del contrato (dos grupos con una misma clave de cohorte no se empalman),
más estricta que la combinación del alumno tipo del evaluador; HC-07 y HC-13 no se pueden revisar con el contrato, como
en el evaluador.
"""

from collections import defaultdict
from dataclasses import dataclass

from app.contract.input import SessionKind, SolverInput
from app.contract.output import HardViolation, ProposedSession

from .constraints import input_problems
from .data import PRACTICE_SPACES, ModelData, covered, overlaps
from .pattern import Grid, minutes


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
    model_data = ModelData.of(data)
    rules = model_data.rules
    violations: list[HardViolation] = []

    by_teacher: dict[str, list[_Busy]] = defaultdict(list)
    by_room: dict[str, list[_Busy]] = defaultdict(list)
    by_cohort: dict[str, list[_Busy]] = defaultdict(list)
    for teacher in data.teachers:
        for slot in teacher.busy:
            by_teacher[teacher.id].append(_Busy(None, False, slot.day, minutes(slot.starts_at), minutes(slot.ends_at)))
    for room in data.rooms:
        for slot in room.busy:
            by_room[room.id].append(_Busy(None, False, slot.day, minutes(slot.starts_at), minutes(slot.ends_at)))

    proposed: dict[str, list[ProposedSession]] = defaultdict(list)
    for session in sessions:
        proposed[session.group_id].append(session)
    for group in data.groups:
        slots = proposed[group.id] if group.movable else group.fixed_sessions
        for slot in slots:
            busy = _Busy(group.id, group.movable, slot.day, minutes(slot.starts_at), minutes(slot.ends_at))
            if group.teacher_id is not None:
                by_teacher[group.teacher_id].append(busy)
            if slot.room_id is not None:
                by_room[slot.room_id].append(busy)
            for key in set(group.cohorts):
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

    for group in model_data.movable:
        violations.extend(_group_violations(model_data, group.id, proposed[group.id]))
    violations.extend(input_problems(model_data))

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


def _group_violations(data: ModelData, group_id: str, sessions: list[ProposedSession]) -> list[HardViolation]:
    group = data.groups[group_id]
    grid: Grid = data.grids[group.campus_code]
    teacher = data.teachers.get(group.teacher_id) if group.teacher_id is not None else None
    violations: list[HardViolation] = []

    def add(rule: str, message: str, room_id: str | None = None, teacher_id: str | None = None) -> None:
        if data.active(rule):
            violations.append(
                HardViolation(rule=rule, group_ids=[group_id], room_id=room_id, teacher_id=teacher_id, message=message)
            )

    for session in sessions:
        start, end = minutes(session.starts_at), minutes(session.ends_at)
        when = f"día {session.day} de {session.starts_at} a {session.ends_at}"
        if (
            session.day not in grid.days
            or start < grid.start
            or end > grid.end
            or (start - grid.start) % grid.block_minutes
            or (end - grid.start) % grid.block_minutes
        ):
            add("HC-04", f"La sesión del {when} cae fuera de la rejilla de {grid.code}.")
        if teacher is not None:
            unavailable = [
                (minutes(s.starts_at), minutes(s.ends_at)) for s in teacher.unavailable if s.day == session.day
            ]
            if overlaps(unavailable, start, end):
                add("HC-09", f"El docente no está disponible el {when}.", teacher_id=teacher.id)
        room = data.rooms.get(session.room_id) if session.room_id is not None else None
        if room is None:
            continue
        if room.capacity < group.capacity:
            add("HC-05", f"El salón tiene {room.capacity} lugares y el grupo {group.capacity}.", room_id=room.id)
        if group.needs_lab and session.kind == SessionKind.PRACTICE and room.type not in PRACTICE_SPACES:
            add("HC-06", f"La práctica del {when} necesita laboratorio, taller o sala de cómputo.", room_id=room.id)
        if data.needs_loan(room.id, group.department_id):
            loans = data.loans.get((room.id, group.department_id), {}).get(session.day, [])
            if not covered(loans, start, end):
                add("HC-10", f"El salón es de otro departamento y no hay préstamo para el {when}.", room_id=room.id)
        blocks = [(minutes(s.starts_at), minutes(s.ends_at)) for s in room.blocks if s.day == session.day]
        if overlaps(blocks, start, end):
            add("HC-11", f"El salón está bloqueado el {when}.", room_id=room.id)

    if sessions:
        scheduled = sum(minutes(session.ends_at) - minutes(session.starts_at) for session in sessions)
        required = group.weekly_hours * 60
        if abs(scheduled - required) > data.tolerance:
            add("HC-12", f"Tiene {scheduled} min de los {required} semanales.")

    return violations
