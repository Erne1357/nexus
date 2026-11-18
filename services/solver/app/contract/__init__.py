"""El contrato JSON de la versión 1 entre Nexus y el motor (espejo de `app/Modules/Solver/Contract/` en PHP)."""

from typing import Any

from pydantic import BaseModel, ValidationError

from .errors import ContractError
from .input import SOFT_RULES, VERSION, ScopeKind, SessionKind, SolverInput
from .output import RunStatus, SolverOutput


def parse_input(data: Any) -> SolverInput:
    """Valida una entrada decodificada de JSON; lanza `ContractError` con la ruta de cada campo malo."""
    try:
        model = SolverInput.model_validate(data)
    except ValidationError as error:
        raise ContractError.from_validation_error(error) from None
    model.assert_consistent()

    return model


def parse_output(data: Any) -> SolverOutput:
    """Valida una salida decodificada de JSON; lanza `ContractError` con la ruta de cada campo malo."""
    try:
        return SolverOutput.model_validate(data)
    except ValidationError as error:
        raise ContractError.from_validation_error(error) from None


def to_dict(model: BaseModel) -> dict[str, Any]:
    """El JSON del contrato: sin campos nulos (PHP los omite) y con las llaves SC-NN."""
    return model.model_dump(mode="json", exclude_none=True, by_alias=True)


__all__ = [
    "SOFT_RULES",
    "VERSION",
    "ContractError",
    "RunStatus",
    "ScopeKind",
    "SessionKind",
    "SolverInput",
    "SolverOutput",
    "parse_input",
    "parse_output",
    "to_dict",
]
