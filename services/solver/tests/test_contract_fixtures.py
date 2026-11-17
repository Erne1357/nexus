"""Los fixtures compartidos con PHP (`tests/Fixtures/solver/v1/*`): se leen, se validan y vuelven a su JSON exacto."""

from pathlib import Path

import pytest

from app.contract import RunStatus, parse_input, parse_output, to_dict
from app.engine import RunContext, TrivialEngine

from .conftest import fixture_files, load


def _ids(paths: list[Path]) -> list[str]:
    return [path.parent.name for path in paths]


INPUTS = fixture_files("input")
OUTPUTS = fixture_files("output")


def test_hay_fixtures_de_entrada():
    assert INPUTS, "Se esperaba al menos tests/Fixtures/solver/v1/tiny/input.json"


@pytest.mark.parametrize("path", INPUTS, ids=_ids(INPUTS))
def test_la_entrada_se_lee_y_vuelve_a_su_json_exacto(path: Path):
    data = load(path)

    assert to_dict(parse_input(data)) == data


@pytest.mark.parametrize("path", OUTPUTS, ids=_ids(OUTPUTS))
def test_la_salida_se_lee_y_vuelve_a_su_json_exacto(path: Path):
    data = load(path)

    assert to_dict(parse_output(data)) == data


@pytest.mark.parametrize("path", INPUTS, ids=_ids(INPUTS))
def test_el_motor_de_prueba_responde_una_salida_valida_del_contrato(path: Path):
    data = parse_input(load(path))

    output = TrivialEngine().solve(data, RunContext())
    payload = to_dict(output)

    assert to_dict(parse_output(payload)) == payload
    assert output.run_id == data.run_id
    assert output.status is RunStatus.PARTIAL
    assert output.hard_violations == []
    assert output.log == ["Motor de prueba del servicio"]
