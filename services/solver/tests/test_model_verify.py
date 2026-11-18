"""El verificador de duras en Python, sobre la solución de referencia de `tiny` y versiones rotas de ella."""

from collections.abc import Callable
from typing import Any

import pytest

from app.contract import parse_input, parse_output
from app.model.verify import hard_violations

G101 = "0190a000-0000-7000-8000-000000000101"
G102 = "0190a000-0000-7000-8000-000000000102"
G103 = "0190a000-0000-7000-8000-000000000103"
AULA_C1 = "0190a000-0000-7000-8000-000000000601"


def violations(data: dict[str, Any], output: dict[str, Any]) -> list[tuple[str, list[str]]]:
    found = hard_violations(parse_input(data), parse_output(output).sessions)

    return sorted((violation.rule, sorted(violation.group_ids)) for violation in found)


def test_la_solucion_de_referencia_no_tiene_violaciones(tiny_input, tiny_output):
    assert violations(tiny_input, tiny_output) == []


def _move(index: int, **changes: Any) -> Callable[[dict, dict], None]:
    def apply(data: dict, output: dict) -> None:
        output["sessions"][index].update(changes)

    return apply


def _drop_last_of(group_id: str) -> Callable[[dict, dict], None]:
    def apply(data: dict, output: dict) -> None:
        last = max(i for i, session in enumerate(output["sessions"]) if session["group_id"] == group_id)
        del output["sessions"][last]

    return apply


def _unavailable(data: dict, output: dict) -> None:
    data["teachers"][0]["unavailable"] = [{"day": 1, "starts_at": "07:00", "ends_at": "07:30"}]


def _shrink_lab(data: dict, output: dict) -> None:
    data["groups"][1]["capacity"] = 31


def _block_room(data: dict, output: dict) -> None:
    data["rooms"][0]["blocks"] = [{"day": 3, "starts_at": "07:30", "ends_at": "08:30"}]


def _no_loan(data: dict, output: dict) -> None:
    data["agreements"][0]["ends_at"] = "09:00"


def _same_cohort_time(data: dict, output: dict) -> None:
    output["sessions"][0].update(day=2, starts_at="08:00", ends_at="09:00", room_id=AULA_C1)


@pytest.mark.parametrize(
    ("break_it", "expected"),
    [
        pytest.param(_move(0, starts_at="06:00", ends_at="07:00"), [("HC-04", [G101])], id="HC-04"),
        pytest.param(_shrink_lab, [("HC-05", [G102]), ("HC-05", [G102])], id="HC-05"),
        pytest.param(_move(5, room_id=AULA_C1), [("HC-06", [G102])], id="HC-06"),
        pytest.param(_unavailable, [("HC-09", [G101])], id="HC-09"),
        pytest.param(_no_loan, [("HC-10", [G102])], id="HC-10"),
        pytest.param(_block_room, [("HC-11", [G101])], id="HC-11"),
        pytest.param(_drop_last_of(G101), [("HC-12", [G101])], id="HC-12"),
        pytest.param(_same_cohort_time, [("HC-03", [G101, G102])], id="HC-03"),
        pytest.param(_move(3, day=1), [("HC-02", [G101, G102]), ("HC-03", [G101, G102])], id="HC-02"),
        pytest.param(_move(7, day=1, starts_at="07:00", ends_at="08:00"), [("HC-01", [G101, G103])], id="HC-01"),
        pytest.param(_move(3, starts_at="08:00", ends_at="09:00"), [], id="dos sesiones del mismo grupo no son HC"),
    ],
)
def test_el_verificador_encuentra_cada_regla_rota(tiny_input, tiny_output, break_it, expected):
    break_it(tiny_input, tiny_output)

    assert violations(tiny_input, tiny_output) == expected


def test_una_regla_desactivada_no_se_revisa(tiny_input, tiny_output):
    _move(0, starts_at="06:00", ends_at="07:00")(tiny_input, tiny_output)
    tiny_input["hard_rules"] = [rule for rule in tiny_input["hard_rules"] if rule != "HC-04"]

    assert violations(tiny_input, tiny_output) == []


def test_el_verificador_no_depende_del_modelo():
    """El verificador solo lee el contrato: un error que compartan el modelo y sus datos no se le escapa."""
    import ast
    from pathlib import Path

    import app.model.verify as verify

    tree = ast.parse(Path(verify.__file__).read_text())
    imported = {node.module for node in ast.walk(tree) if isinstance(node, ast.ImportFrom)}

    assert all(
        module is not None and module.startswith(("app.contract", "collections", "dataclasses")) for module in imported
    )
