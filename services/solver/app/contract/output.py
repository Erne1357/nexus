"""La salida del motor, versión 1 (docs/arquitectura/solver.md, «Salida»). Espejo de los DTOs de PHP."""

from enum import StrEnum

from pydantic import Field, ValidationInfo, field_validator

from .fields import (
    ContractModel,
    Flag,
    HardRule,
    NonEmptyStr,
    NonNegativeInt,
    NonNegativeNumber,
    StrictContractModel,
    Time,
    Weekday,
    check_ends_after,
    enum_of,
)
from .input import VERSION, SessionKind, SessionKindField, Version


class RunStatus(StrEnum):
    OPTIMAL = "optimal"
    FEASIBLE = "feasible"
    PARTIAL = "partial"
    INFEASIBLE = "infeasible"
    ERROR = "error"


RunStatusField = enum_of(RunStatus)


class ProposedSession(ContractModel):
    """Una sesión propuesta; `changed` indica si difiere de lo que había en la entrada."""

    group_id: NonEmptyStr
    day: Weekday
    starts_at: Time
    ends_at: Time
    room_id: NonEmptyStr | None = None
    kind: SessionKindField
    changed: Flag

    @field_validator("ends_at")
    @classmethod
    def _ordered(cls, value: str, info: ValidationInfo) -> str:
        return check_ends_after(info.data.get("starts_at"), value)


class TeacherSuggestion(ContractModel):
    """Un docente sugerido para un grupo sin docente; es sugerencia, la asignación sigue el ranking de la fase 3."""

    group_id: NonEmptyStr
    teacher_id: NonEmptyStr
    reason: NonEmptyStr


class UnscheduledGroup(ContractModel):
    group_id: NonEmptyStr
    reasons: list[NonEmptyStr]


class HardViolation(ContractModel):
    rule: HardRule
    group_ids: list[NonEmptyStr]
    teacher_id: NonEmptyStr | None = None
    room_id: NonEmptyStr | None = None
    message: NonEmptyStr


class RuleScores(StrictContractModel):
    """Penalización por regla blanda; solo van las reglas que el motor calculó."""

    sc_01: NonNegativeInt | None = Field(None, alias="SC-01")
    sc_02: NonNegativeInt | None = Field(None, alias="SC-02")
    sc_03: NonNegativeInt | None = Field(None, alias="SC-03")
    sc_04: NonNegativeInt | None = Field(None, alias="SC-04")
    sc_05: NonNegativeInt | None = Field(None, alias="SC-05")
    sc_06: NonNegativeInt | None = Field(None, alias="SC-06")
    sc_07: NonNegativeInt | None = Field(None, alias="SC-07")
    sc_08: NonNegativeInt | None = Field(None, alias="SC-08")
    sc_09: NonNegativeInt | None = Field(None, alias="SC-09")


class SoftScore(ContractModel):
    """El puntaje blando: total y penalización por regla SC-01 a SC-09. Menor es mejor."""

    total: NonNegativeInt
    by_rule: RuleScores


class RelaxedRule(ContractModel):
    rule: NonEmptyStr
    group_ids: list[NonEmptyStr]


class RunMetrics(ContractModel):
    """Los contadores son opcionales: cada motor reporta los que tiene."""

    elapsed_seconds: NonNegativeNumber
    engine: NonEmptyStr
    engine_version: NonEmptyStr
    variables: NonNegativeInt | None = None
    constraints: NonNegativeInt | None = None
    iterations: NonNegativeInt | None = None
    intermediate_solutions: NonNegativeInt | None = None


class SolverOutput(ContractModel):
    version: Version
    run_id: NonEmptyStr
    status: RunStatusField
    sessions: list[ProposedSession]
    teacher_suggestions: list[TeacherSuggestion] = Field(default_factory=list)
    unscheduled: list[UnscheduledGroup]
    hard_violations: list[HardViolation]
    soft_score: SoftScore
    relaxed_rules: list[RelaxedRule] = Field(default_factory=list)
    metrics: RunMetrics
    log: list[NonEmptyStr] = Field(default_factory=list)

    @field_validator("hard_violations")
    @classmethod
    def _empty_when_clean(cls, value: list[HardViolation], info: ValidationInfo) -> list[HardViolation]:
        status = info.data.get("status")
        if value and status in (RunStatus.OPTIMAL, RunStatus.FEASIBLE):
            raise ValueError(f"debe estar vacía cuando status es {status.value}")

        return value


__all__ = [
    "VERSION",
    "HardViolation",
    "ProposedSession",
    "RelaxedRule",
    "RuleScores",
    "RunMetrics",
    "RunStatus",
    "SessionKind",
    "SoftScore",
    "SolverOutput",
    "TeacherSuggestion",
    "UnscheduledGroup",
]
