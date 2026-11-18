"""Errores del contrato: una lista de (ruta, mensaje) que la API devuelve como 422."""

from typing import Any

from pydantic import ValidationError


class ContractError(ValueError):
    """Un JSON que no cumple el contrato. Cada problema trae la ruta del campo (`groups[3].weekly_hours`)."""

    def __init__(self, problems: list[tuple[str, str]]) -> None:
        self.problems = problems
        super().__init__("; ".join(f"{path}: {message}" if path else message for path, message in problems))

    @classmethod
    def at(cls, path: str, message: str) -> "ContractError":
        return cls([(path, message)])

    def as_detail(self) -> list[dict[str, str]]:
        return [{"path": path, "message": message} for path, message in self.problems]

    @classmethod
    def from_validation_error(cls, error: ValidationError) -> "ContractError":
        return cls([(path_of(item["loc"]), _message(item)) for item in error.errors()])


def path_of(loc: tuple[int | str, ...]) -> str:
    """`("groups", 3, "weekly_hours")` -> `groups[3].weekly_hours`, la misma forma de ruta que usa PHP."""
    path = ""
    for part in loc:
        if isinstance(part, int):
            path += f"[{part}]"
        else:
            path = f"{path}.{part}" if path else str(part)

    return path


def _message(item: Any) -> str:
    kind = item["type"]
    if kind == "missing":
        return "es obligatorio"
    if kind == "value_error":
        return str(item["ctx"]["error"])
    if kind in ("model_type", "dict_type"):
        return "debe ser un objeto"
    if kind == "list_type":
        return "debe ser una lista"
    if kind == "extra_forbidden":
        # Solo los pesos y el puntaje por regla rechazan llaves; en ambos es una regla SC inexistente.
        return "regla blanda desconocida"

    return str(item["msg"])
