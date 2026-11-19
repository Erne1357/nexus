"""La entrada del motor, versión 1 (docs/arquitectura/solver.md, «Entrada»).

Espejo de los DTOs de `app/Modules/Solver/Contract/` en PHP: mismos nombres de campo, mismas reglas.
"""

from enum import StrEnum
from typing import Annotated, Any

from pydantic import BeforeValidator, Field, ValidationInfo, field_validator

from .errors import ContractError
from .fields import (
    ContractModel,
    Flag,
    HardRule,
    NonEmptyStr,
    NonNegativeInt,
    PositiveInt,
    StrictContractModel,
    Time,
    Weekday,
    check_ends_after,
    enum_of,
    int_checker,
)

VERSION = 1

SOFT_RULES = ["SC-01", "SC-02", "SC-03", "SC-04", "SC-05", "SC-06", "SC-07", "SC-08", "SC-09"]


class SessionKind(StrEnum):
    THEORY = "teoria"
    PRACTICE = "practica"
    VIRTUAL = "virtual"


class ScopeKind(StrEnum):
    DEPARTMENT = "department"
    CAREER = "career"


def _version(value: Any) -> int:
    number = int_checker(0)(value)
    if number != VERSION:
        raise ValueError(f"versión no soportada: {number} (se soporta {VERSION})")

    return number


Version = Annotated[int, BeforeValidator(_version)]
ScopeKindField = enum_of(ScopeKind)
SessionKindField = enum_of(SessionKind)


class NamedCode(ContractModel):
    """`term` y `modality`: solo se lee su `code`."""

    code: NonEmptyStr


class CampusGrid(ContractModel):
    """La rejilla de bloques de un campus."""

    code: NonEmptyStr
    # El orden importa: el validador de `day_end` lee `day_start` y `block_minutes`, y pydantic solo le pasa en
    # `info.data` los campos declarados antes. No los reordenes.
    block_minutes: PositiveInt
    day_start: Time
    day_end: Time
    days: list[Weekday]
    uses_rooms: Flag

    @field_validator("day_end")
    @classmethod
    def _ordered(cls, value: str, info: ValidationInfo) -> str:
        start, block = info.data.get("day_start"), info.data.get("block_minutes")
        check_ends_after(start, value)
        if start is not None and block is not None:
            span = (int(value[:2]) * 60 + int(value[3:])) - (int(start[:2]) * 60 + int(start[3:]))
            if span % block != 0:
                raise ValueError(f"la jornada de {start} a {value} no se divide en bloques de {block} minutos")

        return value

    @field_validator("days")
    @classmethod
    def _at_least_one(cls, value: list[int]) -> list[int]:
        if not value:
            raise ValueError("debe tener al menos un día")

        return value


class Scope(ContractModel):
    kind: ScopeKindField
    id: NonEmptyStr


class SessionSlot(ContractModel):
    """Una sesión de un grupo (en la entrada, una que no se mueve)."""

    day: Weekday
    starts_at: Time
    ends_at: Time
    kind: SessionKindField
    room_id: NonEmptyStr | None = None

    @field_validator("ends_at")
    @classmethod
    def _ordered(cls, value: str, info: ValidationInfo) -> str:
        return check_ends_after(info.data.get("starts_at"), value)


class HintSession(SessionSlot):
    """Una sesión de la solución inicial sugerida: un `SessionSlot` con su grupo. El motor puede ignorarla."""

    group_id: NonEmptyStr


class TimeSlot(ContractModel):
    """Un bloque no disponible, preferido, ocupado o bloqueado; `campus_code` solo en las ocupaciones de docentes."""

    day: Weekday
    starts_at: Time
    ends_at: Time
    campus_code: NonEmptyStr | None = None

    @field_validator("ends_at")
    @classmethod
    def _ordered(cls, value: str, info: ValidationInfo) -> str:
        return check_ends_after(info.data.get("starts_at"), value)


class GroupInput(ContractModel):
    """Un grupo a programar (o, con `movable = false`, solo ocupación)."""

    id: NonEmptyStr
    campus_code: NonEmptyStr
    subject_code: NonEmptyStr
    department_id: NonEmptyStr
    owner_career_id: NonEmptyStr
    study_plan_id: NonEmptyStr
    semester: PositiveInt
    weekly_hours: PositiveInt
    practice_hours: NonNegativeInt
    needs_lab: Flag
    capacity: PositiveInt
    teacher_id: NonEmptyStr | None = None
    cohorts: list[NonEmptyStr] = Field(default_factory=list)
    fixed_sessions: list[SessionSlot] = Field(default_factory=list)
    candidate_room_ids: list[NonEmptyStr] = Field(default_factory=list)
    movable: Flag = True
    current_sessions: list[SessionSlot] = Field(default_factory=list)


class TeacherInput(ContractModel):
    id: NonEmptyStr
    remaining_hours: NonNegativeInt
    honorarios: Flag
    unavailable: list[TimeSlot] = Field(default_factory=list)
    preferred: list[TimeSlot] = Field(default_factory=list)
    busy: list[TimeSlot] = Field(default_factory=list)


class RoomInput(ContractModel):
    id: NonEmptyStr
    campus_code: NonEmptyStr
    type: NonEmptyStr
    capacity: PositiveInt
    building_id: NonEmptyStr
    owner_department_id: NonEmptyStr | None = None
    blocks: list[TimeSlot] = Field(default_factory=list)
    busy: list[TimeSlot] = Field(default_factory=list)


class RoomAgreement(ContractModel):
    """Un préstamo aceptado de un salón a otro departamento en un bloque (HC-10).

    `group_id` es opcional: el grupo para el que se pidió, como lo revisa el validador (`RoomLoan`, por grupo). Sin él,
    el préstamo cubre a todos los grupos del departamento, como lo traduce hoy `ContractState`. Agregarlo no cambia la
    versión del contrato (campo opcional).
    """

    room_id: NonEmptyStr
    to_department_id: NonEmptyStr
    day: Weekday
    starts_at: Time
    ends_at: Time
    group_id: NonEmptyStr | None = None

    @field_validator("ends_at")
    @classmethod
    def _ordered(cls, value: str, info: ValidationInfo) -> str:
        return check_ends_after(info.data.get("starts_at"), value)


class SubjectRoomDefault(ContractModel):
    subject_code: NonEmptyStr
    campus_code: NonEmptyStr
    room_id: NonEmptyStr
    priority: NonNegativeInt


MAX_WEIGHT = 10_000


def _weight(value: Any) -> int:
    """Un peso: entero de 0 a 10 000 (más desbordaría los coeficientes de 64 bits del motor)."""
    checked = int_checker(0)(value)
    if checked > MAX_WEIGHT:
        raise ValueError("debe estar entre 0 y 10 000")
    return checked


Weight = Annotated[int, BeforeValidator(_weight)]


class SoftWeights(StrictContractModel):
    """El peso de cada regla blanda SC-01 a SC-09. Cero la desactiva; una llave que falta vale cero."""

    sc_01: Weight = Field(0, alias="SC-01")
    sc_02: Weight = Field(0, alias="SC-02")
    sc_03: Weight = Field(0, alias="SC-03")
    sc_04: Weight = Field(0, alias="SC-04")
    sc_05: Weight = Field(0, alias="SC-05")
    sc_06: Weight = Field(0, alias="SC-06")
    sc_07: Weight = Field(0, alias="SC-07")
    sc_08: Weight = Field(0, alias="SC-08")
    sc_09: Weight = Field(0, alias="SC-09")

    def as_dict(self) -> dict[str, int]:
        """`{"SC-01": peso, ...}` para los motores."""
        return {rule: getattr(self, rule.lower().replace("-", "_")) for rule in SOFT_RULES}


class SolverOptions(ContractModel):
    threads: PositiveInt | None = None
    seed: NonNegativeInt | None = None
    suggest_teachers: Flag = False
    return_intermediate: Flag = False


class SolverInput(ContractModel):
    version: Version
    run_id: NonEmptyStr
    term: NamedCode
    modality: NamedCode
    campuses: list[CampusGrid]
    time_limit_seconds: PositiveInt
    scope: Scope
    travel_minutes: NonNegativeInt
    weekly_hours_tolerance: NonNegativeInt = 0
    groups: list[GroupInput]
    teachers: list[TeacherInput]
    rooms: list[RoomInput] = Field(default_factory=list)
    agreements: list[RoomAgreement] = Field(default_factory=list)
    subject_room_defaults: list[SubjectRoomDefault] = Field(default_factory=list)
    hard_rules: list[HardRule]
    soft_weights: SoftWeights
    options: SolverOptions = Field(default_factory=SolverOptions)
    hint_sessions: list[HintSession] = Field(default_factory=list)

    def assert_consistent(self) -> None:
        """Las referencias cruzadas que PHP revisa en `SolverInput::assertConsistent`."""
        problems: list[tuple[str, str]] = []

        campus_codes: set[str] = set()
        for i, campus in enumerate(self.campuses):
            if campus.code in campus_codes:
                problems.append((f"campuses[{i}].code", f"campus repetido: {campus.code}"))
            campus_codes.add(campus.code)

        room_ids = {room.id for room in self.rooms}
        teacher_ids = {teacher.id for teacher in self.teachers}

        for i, room in enumerate(self.rooms):
            if room.campus_code not in campus_codes:
                problems.append((f"rooms[{i}].campus_code", f"el campus {room.campus_code} no está en campuses"))

        group_ids: set[str] = set()
        for i, group in enumerate(self.groups):
            at = f"groups[{i}]"
            if group.campus_code not in campus_codes:
                problems.append((f"{at}.campus_code", f"el campus {group.campus_code} no está en campuses"))
            if group.id in group_ids:
                problems.append((f"{at}.id", f"id de grupo repetido: {group.id}"))
            group_ids.add(group.id)
            if group.teacher_id is not None and group.teacher_id not in teacher_ids:
                problems.append((f"{at}.teacher_id", "el docente no está en teachers"))
            for j, room_id in enumerate(group.candidate_room_ids):
                if room_id not in room_ids:
                    problems.append((f"{at}.candidate_room_ids[{j}]", "el salón no está en rooms"))
            for j, session in enumerate(group.fixed_sessions):
                if session.room_id is not None and session.room_id not in room_ids:
                    problems.append((f"{at}.fixed_sessions[{j}].room_id", "el salón no está en rooms"))

        for i, agreement in enumerate(self.agreements):
            if agreement.group_id is not None and agreement.group_id not in group_ids:
                problems.append((f"agreements[{i}].group_id", "el grupo no está en groups"))
        for i, hint in enumerate(self.hint_sessions):
            if hint.group_id not in group_ids:
                problems.append((f"hint_sessions[{i}].group_id", "el grupo no está en groups"))
            if hint.room_id is not None and hint.room_id not in room_ids:
                problems.append((f"hint_sessions[{i}].room_id", "el salón no está en rooms"))

        if problems:
            raise ContractError(problems)
