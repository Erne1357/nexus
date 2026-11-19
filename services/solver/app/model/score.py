"""El puntaje blando SC-01 a SC-09 de una salida del contrato, en Python: el mismo número que el `SoftScorer` de PHP.

Espejo de `Scheduling/Rules/Soft/*` y `SoftScorer` sobre la foto de `Solver/Evaluation/ContractState`
(docs/arquitectura/solver.md, «Evaluador en memoria»; reglas-de-negocio.md, «Unidades del puntaje blando»). El motor
CP-SAT lo usa para el `soft_score` de su salida y para aceptar vecindarios, así que lo que reporta es lo que mide PHP;
el objetivo del modelo (`objective.py`) es la misma cuenta, como términos lineales.

Como en PHP:

- El alcance son los grupos movibles: cada regla cuenta lo que toca a alguno. Los grupos fijos entran con sus
  `fixed_sessions`; la ocupación de fuera de la corrida, como grupos de contexto: los `busy` de un docente, uno por
  docente y campus (sin `campus_code`, el campus de su primer grupo de la entrada, o el primer campus), sin salón.
- Una fracción de hora cuenta como hora (`Timeline::hours`), por hueco, por sesión o por par, según la regla.
- Un peso cero no se calcula: su regla da cero.

Es independiente del modelo, como `verify.py`: solo lee el contrato.
"""

import re
from collections import defaultdict
from collections.abc import Iterable
from dataclasses import dataclass
from itertools import pairwise

from app.contract.input import SOFT_RULES, SolverInput
from app.contract.output import ProposedSession, RuleScores, SoftScore

#: El prefijo de los grupos de contexto, como `ContractState::CONTEXT`.
CONTEXT = "busy:"


@dataclass(frozen=True, slots=True)
class Session:
    """Una sesión de la foto: de un grupo de la entrada o de contexto, en minutos."""

    group_id: str
    day: int
    start: int
    end: int
    room_id: str | None
    campus: str


@dataclass(frozen=True)
class Score:
    """El puntaje: el total, la penalización con peso de cada regla (suma el total) y sin peso, en su unidad."""

    total: int
    by_rule: dict[str, int]
    units: dict[str, int]

    def to_contract(self) -> SoftScore:
        return SoftScore(total=self.total, by_rule=RuleScores.model_validate(self.by_rule))


def hours(minutes: int) -> int:
    """`Timeline::hours`: una fracción de hora cuenta como hora."""
    return 0 if minutes <= 0 else -(-minutes // 60)


def gaps(sessions: list[Session]) -> Iterable[tuple[Session, Session, int]]:
    """`Timeline::gaps`: en las sesiones de un día en orden, cada hueco con la sesión que termina más tarde antes de
    él, la siguiente y sus minutos. Las que se empalman o se tocan no dejan hueco."""
    latest: Session | None = None
    for session in sessions:
        if latest is not None and session.start > latest.end:
            yield latest, session, session.start - latest.end
        if latest is None or session.end > latest.end:
            latest = session


def uncovered(day: int, start: int, end: int, blocks: list[tuple[int, int, int]]) -> int:
    """`Timeline::uncovered`: los minutos de [start, end) que ningún bloque de su día cubre."""
    covered = sorted(
        (max(b_start, start), min(b_end, end))
        for b_day, b_start, b_end in blocks
        if b_day == day and b_start < end and b_end > start
    )
    minutes = 0
    reached = start
    for c_start, c_end in covered:
        minutes += max(0, c_start - reached)
        reached = max(reached, c_end)

    return minutes + max(0, end - reached)


def by_day(sessions: Iterable[Session]) -> dict[int, list[Session]]:
    """`Timeline::byDay`: por día, en orden de inicio y fin (estable, como `usort` en PHP 8)."""
    days: dict[int, list[Session]] = defaultdict(list)
    for session in sessions:
        days[session.day].append(session)
    for day_sessions in days.values():
        day_sessions.sort(key=lambda session: (session.start, session.end))

    return days


def minutes(clock: str) -> int:
    return int(clock[:2]) * 60 + int(clock[3:])


class SoftScorer:
    """El puntaje de las sesiones propuestas para los grupos movibles de una entrada. Lo que no depende de la propuesta
    (grupos fijos, ocupación de fuera, cohortes, preferidos, edificios, salones por defecto) se arma una vez."""

    def __init__(self, data: SolverInput) -> None:
        self.data = data
        self.weights = data.soft_weights.as_dict()
        self.groups = {group.id: group for group in data.groups}
        self.movable = [group.id for group in data.groups if group.movable]
        self.campus_days = {campus.code: len(set(campus.days)) for campus in data.campuses}
        self.preferred = {
            teacher.id: [(slot.day, minutes(slot.starts_at), minutes(slot.ends_at)) for slot in teacher.preferred]
            for teacher in data.teachers
        }
        self.honorarios = {teacher.id for teacher in data.teachers if teacher.honorarios}
        self.buildings = {room.id: room.building_id for room in data.rooms}
        self.defaults: dict[tuple[str, str], set[str]] = defaultdict(set)
        for default in data.subject_room_defaults:
            self.defaults[(default.subject_code, default.campus_code)].add(default.room_id)
        self.members: dict[str, list[str]] = defaultdict(list)
        for group in data.groups:
            for key in group.cohorts:
                self.members[key].append(group.id)
        self.fixed: dict[str, list[Session]] = {
            group.id: [
                Session(
                    group.id, slot.day, minutes(slot.starts_at), minutes(slot.ends_at), slot.room_id, group.campus_code
                )
                for slot in group.fixed_sessions
            ]
            for group in data.groups
            if not group.movable
        }
        first_campus: dict[str, str] = {}
        for group in data.groups:
            if group.teacher_id is not None:
                first_campus.setdefault(group.teacher_id, group.campus_code)
        default_campus = data.campuses[0].code if data.campuses else ""
        #: Las sesiones de contexto de cada docente, por campus en el orden en que aparecen, como en `ContractState`.
        self.busy: dict[str, list[Session]] = {}
        for teacher in data.teachers:
            by_campus: dict[str, list[Session]] = {}
            for slot in teacher.busy:
                campus = slot.campus_code or first_campus.get(teacher.id, default_campus)
                group_id = f"{CONTEXT}teacher:{teacher.id}:{campus}"
                by_campus.setdefault(campus, []).append(
                    Session(group_id, slot.day, minutes(slot.starts_at), minutes(slot.ends_at), None, campus)
                )
            self.busy[teacher.id] = [session for sessions in by_campus.values() for session in sessions]

    def score(self, sessions: Iterable[ProposedSession], scope: Iterable[str] | None = None) -> Score:
        """El puntaje de las sesiones propuestas (de grupos movibles) sobre los grupos del alcance (los movibles, si no
        se dice otro)."""
        proposed: dict[str, list[Session]] = defaultdict(list)
        for session in sessions:
            campus = self.groups[session.group_id].campus_code
            proposed[session.group_id].append(
                Session(
                    session.group_id,
                    session.day,
                    minutes(session.starts_at),
                    minutes(session.ends_at),
                    session.room_id,
                    campus,
                )
            )
        state = _State(self, proposed, list(self.movable if scope is None else scope))
        units = dict.fromkeys(SOFT_RULES, 0)
        by_rule = dict.fromkeys(SOFT_RULES, 0)
        for rule in SOFT_RULES:
            weight = self.weights[rule]
            if weight == 0:
                continue
            units[rule] = RULES[rule](state)
            by_rule[rule] = weight * units[rule]

        return Score(sum(by_rule.values()), by_rule, units)


class _State:
    """La foto de una propuesta: las sesiones de cada grupo y de cada docente, y el alcance."""

    def __init__(self, scorer: SoftScorer, proposed: dict[str, list[Session]], scope: list[str]) -> None:
        self.scorer = scorer
        self.scope = scope
        self.in_scope = set(scope)
        self.proposed = proposed
        teacher_ids: dict[str, None] = {}
        for group_id in scope:
            teacher_id = scorer.groups[group_id].teacher_id
            if teacher_id is not None:
                teacher_ids[teacher_id] = None
        self.teacher_ids = list(teacher_ids)
        self._teacher_sessions: dict[str, list[Session]] | None = None

    def sessions_of(self, group_id: str) -> list[Session]:
        group = self.scorer.groups[group_id]

        return self.proposed.get(group_id, []) if group.movable else self.scorer.fixed[group_id]

    def teacher_sessions(self, teacher_id: str) -> list[Session]:
        """`ScheduleState::teacherSessions`: las de sus grupos de la entrada, en orden, y luego su ocupación de
        fuera."""
        if self._teacher_sessions is None:
            wanted = set(self.teacher_ids)
            found: dict[str, list[Session]] = defaultdict(list)
            for group in self.scorer.data.groups:
                if group.teacher_id in wanted:
                    found[group.teacher_id].extend(self.sessions_of(group.id))
            for wanted_id in self.teacher_ids:
                found[wanted_id].extend(self.scorer.busy.get(wanted_id, []))
            self._teacher_sessions = found

        return self._teacher_sessions.get(teacher_id, [])

    def touches(self, *group_ids: str) -> bool:
        return any(group_id in self.in_scope for group_id in group_ids)


#: El prefijo de las cohortes del núcleo sin combinación: cuentan para HC-03 (no empalme) pero no para SC-01, como en
#: `StudentGaps` de PHP.
COMBINATION = "combination:"


def student_gaps(state: _State) -> int:
    """SC-01 (`StudentGaps`): las horas de hueco de cada cohorte de un grupo del alcance en cada día, junto a una sesión
    de un grupo del alcance. Las cohortes `combination:` no cuentan (`gap_cohorts`)."""
    keys = dict.fromkeys(key for group_id in state.scope for key in gap_cohorts(state.scorer.groups[group_id].cohorts))
    total = 0
    for key in keys:
        sessions = [session for member in state.scorer.members[key] for session in state.sessions_of(member)]
        for day_sessions in by_day(sessions).values():
            for before, after, gap in gaps(day_sessions):
                if state.touches(before.group_id, after.group_id):
                    total += hours(gap)

    return total


def gap_cohorts(cohorts: Iterable[str]) -> list[str]:
    """Las cohortes de un grupo que cuentan para SC-01: todas menos las `combination:`, que solo impiden el empalme
    (HC-03)."""
    return [key for key in cohorts if not key.startswith(COMBINATION)]


def group_day_spread(state: _State) -> int:
    """SC-02 (`GroupDaySpread`): las horas de un grupo en un día por encima del techo de sus horas semanales entre los
    días de su campus."""
    total = 0
    for group_id in state.scope:
        group = state.scorer.groups[group_id]
        sessions = state.sessions_of(group_id)
        if not sessions:
            continue
        per_day: dict[int, int] = defaultdict(int)
        for session in sessions:
            per_day[session.day] += session.end - session.start
        weekly = max(group.weekly_hours, group.practice_hours)
        days = max(1, state.scorer.campus_days[group.campus_code])
        share = max(1, -(-weekly // days))
        total += sum(max(0, hours(day_minutes) - share) for day_minutes in per_day.values())

    return total


def default_room(state: _State) -> int:
    """SC-03 (`DefaultRoom`): las horas de sesión en un salón que no es de los por defecto de la materia en su campus,
    si la materia los tiene."""
    total = 0
    for group_id in state.scope:
        group = state.scorer.groups[group_id]
        defaults = state.scorer.defaults.get((group.subject_code, group.campus_code))
        if not defaults:
            continue
        total += sum(
            hours(session.end - session.start)
            for session in state.sessions_of(group_id)
            if session.room_id is not None and session.room_id not in defaults
        )

    return total


def _outside_preferred(state: _State, honorarios_only: bool) -> int:
    total = 0
    for group_id in state.scope:
        teacher_id = state.scorer.groups[group_id].teacher_id
        blocks = state.scorer.preferred.get(teacher_id, []) if teacher_id is not None else []
        if not blocks or (honorarios_only and teacher_id not in state.scorer.honorarios):
            continue
        total += sum(
            hours(uncovered(session.day, session.start, session.end, blocks)) for session in state.sessions_of(group_id)
        )

    return total


def preferred_blocks(state: _State) -> int:
    """SC-04 (`PreferredBlocks`): las horas de sesión fuera de los bloques preferidos del docente, si declaró alguno."""
    return _outside_preferred(state, honorarios_only=False)


def teacher_gaps(state: _State) -> int:
    """SC-05 (`TeacherGaps`): las horas de hueco del docente en cada día, en todos sus campus, junto a una sesión de un
    grupo del alcance."""
    total = 0
    for teacher_id in state.teacher_ids:
        for day_sessions in by_day(state.teacher_sessions(teacher_id)).values():
            for before, after, gap in gaps(day_sessions):
                if state.touches(before.group_id, after.group_id):
                    total += hours(gap)

    return total


def building_changes(state: _State) -> int:
    """SC-06 (`BuildingChanges`): los cambios de edificio entre dos sesiones seguidas del docente sin hueco entre ellas,
    las dos con salón conocido."""
    buildings = state.scorer.buildings
    total = 0
    for teacher_id in state.teacher_ids:
        for day_sessions in by_day(state.teacher_sessions(teacher_id)).values():
            for before, after in pairwise(day_sessions):
                origin = buildings.get(before.room_id) if before.room_id is not None else None
                target = buildings.get(after.room_id) if after.room_id is not None else None
                if (
                    after.start == before.end
                    and origin is not None
                    and target is not None
                    and origin != target
                    and state.touches(before.group_id, after.group_id)
                ):
                    total += 1

    return total


def consecutive_semesters(state: _State) -> int:
    """SC-07 (`ConsecutiveSemesters`): las horas de empalme de cada par de grupos de cohortes de plan del mismo plan y
    campus en semestres seguidos, una vez por par."""
    seen: set[tuple[str, str]] = set()
    total = 0
    for group_id in state.scope:
        for key in state.scorer.groups[group_id].cohorts:
            parts = key.split(":")
            if parts[0] != "plan" or len(parts) != 4:
                continue
            _, plan, semester, campus = parts
            number = php_int(semester)
            for other_semester in (number - 1, number + 1):
                for other_id in state.scorer.members.get(f"plan:{plan}:{other_semester}:{campus}", []):
                    pair = (min(group_id, other_id), max(group_id, other_id))
                    if other_id == group_id or pair in seen:
                        continue
                    seen.add(pair)
                    overlap = sum(
                        max(0, min(mine.end, theirs.end) - max(mine.start, theirs.start))
                        for mine in state.sessions_of(group_id)
                        for theirs in state.sessions_of(other_id)
                        if mine.day == theirs.day
                    )
                    total += hours(overlap)

    return total


def php_int(value: str) -> int:
    """`(int) $value` en PHP: el entero del principio, o cero."""
    match = re.match(r"\s*[+-]?\d+", value)

    return int(match.group()) if match else 0


def honorarios_availability(state: _State) -> int:
    """SC-08 (`HonorariosAvailability`): SC-04 solo de los docentes de honorarios."""
    return _outside_preferred(state, honorarios_only=True)


def campus_travel(state: _State) -> int:
    """SC-09 (`CampusTravelTime` con `CampusTravel`): los pares de sesiones seguidas del docente en campus distintos con
    menos de `travel_minutes` entre ellas, que tocan el alcance."""
    minimum = state.scorer.data.travel_minutes
    total = 0
    for teacher_id in state.teacher_ids:
        for day_sessions in by_day(state.teacher_sessions(teacher_id)).values():
            for first, following in pairwise(day_sessions):
                free = following.start - first.end
                if (
                    0 <= free < minimum
                    and first.campus != following.campus
                    and state.touches(first.group_id, following.group_id)
                ):
                    total += 1

    return total


RULES = {
    "SC-01": student_gaps,
    "SC-02": group_day_spread,
    "SC-03": default_room,
    "SC-04": preferred_blocks,
    "SC-05": teacher_gaps,
    "SC-06": building_changes,
    "SC-07": consecutive_semesters,
    "SC-08": honorarios_availability,
    "SC-09": campus_travel,
}
