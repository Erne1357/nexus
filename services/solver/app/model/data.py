"""Lo que el modelo lee de la entrada, indexado una sola vez: rejillas, grupos, salones, préstamos y la ocupación fija.

Traduce el contrato como lo hace PHP (`Evaluation/ContractState` y `Engines/PhpHeuristic/Builder`):

- Los grupos con `movable = false` entran con sus `fixed_sessions` como ocupación fija de su docente, de sus salones y
  de sus cohortes. Los `fixed_sessions` de un grupo movible no cuentan: el grupo se programa desde cero (PS-07).
- `teachers[].busy` es ocupación fija del docente en cualquier campus (HC-01 no ve campus) y `rooms[].busy`, del salón.
- Un salón con dueño de otro departamento se puede usar donde la unión de los préstamos aceptados (`agreements`) de
  ese salón y ese día que le tocan al grupo cubre toda la sesión (HC-10; los contiguos cubren juntos, como `RoomLoan`).
  Un préstamo con `group_id` es solo de ese grupo, como en el validador; uno sin él, de todo el departamento, como lo
  traduce `ContractState` mientras la entrada no traiga el grupo.
- Todo traslape se cuenta en minutos con intervalos medio abiertos: dos sesiones contiguas no se empalman.
"""

from collections import defaultdict
from dataclasses import dataclass, field

from app.contract.input import GroupInput, RoomInput, SolverInput, TeacherInput, TimeSlot

from .pattern import Grid, minutes

#: Las reglas duras del contrato, en orden.
HARD_RULES = tuple(f"HC-{number:02d}" for number in range(1, 14))

#: Los tipos de salón que son espacio de práctica (HC-06), como `RoomType::isPracticeSpace`; uno desconocido es aula.
PRACTICE_SPACES = frozenset({"laboratorio", "taller", "sala_computo"})

#: Un rango ocupado en un día: [inicio, fin) en minutos.
Span = tuple[int, int]


def overlaps(spans: list[Span], start: int, end: int) -> bool:
    """Si algún rango se empalma con [start, end)."""
    return any(start < span_end and span_start < end for span_start, span_end in spans)


def covered(ranges: list[Span], start: int, end: int) -> bool:
    """Si la unión de los rangos cubre todo [start, end), como `RoomLoan::covers`."""
    reached = start
    for range_start, range_end in sorted(ranges):
        if range_start > reached:
            break
        reached = max(reached, range_end)

    return reached >= end


@dataclass
class ModelData:
    """La entrada lista para armar el modelo; `rules` son las reglas duras activas (`hard_rules`)."""

    source: SolverInput
    rules: frozenset[str]
    grids: dict[str, Grid]
    groups: dict[str, GroupInput]
    movable: list[GroupInput]
    teachers: dict[str, TeacherInput]
    rooms: dict[str, RoomInput]
    rooms_by_campus: dict[str, list[str]]
    #: docente -> día -> rangos no disponibles (HC-09).
    teacher_unavailable: dict[str, dict[int, list[Span]]] = field(default_factory=dict)
    #: docente -> día -> rangos ocupados fuera de la corrida o por grupos fijos (HC-01).
    teacher_fixed: dict[str, dict[int, list[Span]]] = field(default_factory=dict)
    #: salón -> día -> rangos ocupados fuera de la corrida o por grupos fijos (HC-02).
    room_fixed: dict[str, dict[int, list[Span]]] = field(default_factory=dict)
    #: salón -> día -> rangos bloqueados (HC-11).
    room_blocks: dict[str, dict[int, list[Span]]] = field(default_factory=dict)
    #: cohorte -> día -> rangos ocupados por grupos fijos (HC-03).
    cohort_fixed: dict[str, dict[int, list[Span]]] = field(default_factory=dict)
    #: (salón, departamento) -> día -> rangos prestados a todo el departamento (HC-10).
    loans: dict[tuple[str, str], dict[int, list[Span]]] = field(default_factory=dict)
    #: (salón, grupo) -> día -> rangos prestados a un solo grupo (`agreements[].group_id`).
    group_loans: dict[tuple[str, str], dict[int, list[Span]]] = field(default_factory=dict)

    @classmethod
    def of(cls, data: SolverInput) -> "ModelData":
        groups = {group.id: group for group in data.groups}
        rooms_by_campus: dict[str, list[str]] = defaultdict(list)
        for room in data.rooms:
            rooms_by_campus[room.campus_code].append(room.id)

        model = cls(
            source=data,
            rules=frozenset(data.hard_rules),
            grids={campus.code: Grid.of(campus) for campus in data.campuses},
            groups=groups,
            movable=[group for group in data.groups if group.movable],
            teachers={teacher.id: teacher for teacher in data.teachers},
            rooms={room.id: room for room in data.rooms},
            rooms_by_campus=dict(rooms_by_campus),
        )

        for teacher in data.teachers:
            _add_slots(model.teacher_unavailable, teacher.id, teacher.unavailable)
            _add_slots(model.teacher_fixed, teacher.id, teacher.busy)
        for room in data.rooms:
            _add_slots(model.room_blocks, room.id, room.blocks)
            _add_slots(model.room_fixed, room.id, room.busy)
        for group in data.groups:
            if group.movable:
                continue
            for session in group.fixed_sessions:
                span = (minutes(session.starts_at), minutes(session.ends_at))
                if group.teacher_id is not None:
                    _add(model.teacher_fixed, group.teacher_id, session.day, span)
                if session.room_id is not None:
                    _add(model.room_fixed, session.room_id, session.day, span)
                for key in sorted(set(group.cohorts)):
                    _add(model.cohort_fixed, key, session.day, span)
        for agreement in data.agreements:
            span = (minutes(agreement.starts_at), minutes(agreement.ends_at))
            if agreement.group_id is None:
                _add(model.loans, (agreement.room_id, agreement.to_department_id), agreement.day, span)
            else:
                _add(model.group_loans, (agreement.room_id, agreement.group_id), agreement.day, span)

        return model

    def active(self, rule: str) -> bool:
        return rule in self.rules

    @property
    def tolerance(self) -> int:
        """La tolerancia de HC-12, en minutos."""
        return self.source.weekly_hours_tolerance

    def is_practice_space(self, room_id: str) -> bool:
        return self.rooms[room_id].type in PRACTICE_SPACES

    def needs_loan(self, room_id: str, department_id: str) -> bool:
        """Si el salón es de otro departamento (HC-10)."""
        owner = self.rooms[room_id].owner_department_id

        return owner is not None and owner != department_id

    def has_loans(self, room_id: str, department_id: str, group_id: str | None = None) -> bool:
        return (room_id, department_id) in self.loans or (room_id, group_id) in self.group_loans

    def has_own_loans(self, group_id: str) -> bool:
        """Si el grupo tiene préstamos solo suyos: su salón no se puede contar con el de su departamento."""
        return any(key[1] == group_id for key in self.group_loans)

    def loan_covers(
        self, room_id: str, department_id: str, day: int, start: int, end: int, group_id: str | None = None
    ) -> bool:
        ranges = self.loans.get((room_id, department_id), {}).get(day, [])
        if group_id is not None:
            ranges = ranges + self.group_loans.get((room_id, group_id), {}).get(day, [])

        return covered(ranges, start, end)

    def room_reason(
        self, room_id: str, department_id: str, day: int, start: int, end: int, group_id: str | None = None
    ) -> str | None:
        """La regla activa que impide usar el salón en [start, end) a un grupo del departamento (o al grupo, para sus
        préstamos propios), o `None`: bloqueo (HC-11), ocupación fija (HC-02) o salón ajeno sin préstamo que lo cubra
        (HC-10)."""
        if self.active("HC-11") and overlaps(self.room_blocks.get(room_id, {}).get(day, []), start, end):
            return "HC-11"
        if self.active("HC-02") and overlaps(self.room_fixed.get(room_id, {}).get(day, []), start, end):
            return "HC-02"
        if (
            self.active("HC-10")
            and self.needs_loan(room_id, department_id)
            and not self.loan_covers(room_id, department_id, day, start, end, group_id)
        ):
            return "HC-10"

        return None


def _add(index: dict, key: object, day: int, span: Span) -> None:
    index.setdefault(key, {}).setdefault(day, []).append(span)


def _add_slots(index: dict, key: str, slots: list[TimeSlot]) -> None:
    for slot in slots:
        _add(index, key, slot.day, (minutes(slot.starts_at), minutes(slot.ends_at)))
