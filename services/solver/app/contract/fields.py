"""Tipos de campo del contrato, con las mismas reglas y mensajes que `ContractFields` en PHP.

El contrato es estricto con los tipos (un `"3"` no es un entero, un `1` no es un booleano) y trata `null` como
campo ausente. Los mensajes van en español y se leen detrás de la ruta del campo: «groups[3].weekly_hours: debe
ser un entero de 1 en adelante».
"""

import re
from collections.abc import Callable
from enum import StrEnum
from typing import Annotated, Any

from pydantic import BaseModel, BeforeValidator, ConfigDict, model_validator

_TIME = re.compile(r"^([01]\d|2[0-3]):[0-5]\d$")
_RULE = re.compile(r"^HC-\d{2}$")


def _non_empty_str(value: Any) -> str:
    if not isinstance(value, str) or value == "":
        raise ValueError("debe ser una cadena no vacía")

    return value


def _time(value: Any) -> str:
    text = _non_empty_str(value)
    if not _TIME.match(text):
        raise ValueError("debe ser una hora HH:MM")

    return text


def _flag(value: Any) -> bool:
    if not isinstance(value, bool):
        raise ValueError("debe ser verdadero o falso")

    return value


def _hard_rule(value: Any) -> str:
    text = _non_empty_str(value)
    if not _RULE.match(text):
        raise ValueError("debe tener la forma HC-NN")

    return text


def _number(value: Any) -> float:
    if isinstance(value, bool) or not isinstance(value, int | float) or value < 0:
        raise ValueError("debe ser un número de 0 en adelante")

    return float(value)


def _int_message(minimum: int, maximum: int | None) -> str:
    if maximum is not None:
        return f"debe ser un entero entre {minimum} y {maximum}"

    return f"debe ser un entero de {minimum} en adelante"


def int_checker(minimum: int = 0, maximum: int | None = None) -> Callable[[Any], int]:
    """Valida un entero estricto (ni cadena, ni decimal, ni booleano) dentro de los límites."""

    def check(value: Any) -> int:
        if (
            isinstance(value, bool)
            or not isinstance(value, int)
            or value < minimum
            or (maximum is not None and value > maximum)
        ):
            raise ValueError(_int_message(minimum, maximum))

        return value

    return check


def bounded_int(minimum: int = 0, maximum: int | None = None) -> Any:
    return Annotated[int, BeforeValidator(int_checker(minimum, maximum))]


def enum_of(enum: type[StrEnum]) -> Any:
    """Un valor de la enumeración, con el mensaje «debe ser uno de: …»."""

    def check(value: Any) -> StrEnum:
        if isinstance(value, str):
            try:
                return enum(value)
            except ValueError:
                pass
        allowed = ", ".join(case.value for case in enum)
        raise ValueError(f"debe ser uno de: {allowed}")

    return Annotated[enum, BeforeValidator(check)]


NonNegativeInt = bounded_int(0)
PositiveInt = bounded_int(1)
Weekday = bounded_int(1, 6)
NonEmptyStr = Annotated[str, BeforeValidator(_non_empty_str)]
Time = Annotated[str, BeforeValidator(_time)]
Flag = Annotated[bool, BeforeValidator(_flag)]
HardRule = Annotated[str, BeforeValidator(_hard_rule)]
NonNegativeNumber = Annotated[float, BeforeValidator(_number)]


def check_ends_after(start: str | None, end: str) -> str:
    """El fin de un bloque debe ser posterior a su inicio (`start` es `None` si ya falló su propia validación)."""
    if start is not None and end <= start:
        raise ValueError(f"debe ser posterior a {start}")

    return end


class ContractModel(BaseModel):
    """Base de los objetos del contrato: las llaves desconocidas se ignoran y `null` equivale a ausente."""

    model_config = ConfigDict(extra="ignore")

    @model_validator(mode="before")
    @classmethod
    def _drop_nulls(cls, data: Any) -> Any:
        if isinstance(data, dict):
            return {key: value for key, value in data.items() if value is not None}

        return data


class StrictContractModel(ContractModel):
    """Como `ContractModel`, pero una llave desconocida es un error (pesos y puntaje por regla)."""

    model_config = ConfigDict(extra="forbid", populate_by_name=False)
