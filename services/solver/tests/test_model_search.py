"""Las búsquedas de una primera solución sin duras (`search.py`): niveles por necesidad y vecindarios (LNS)."""

import json

import pytest
from ortools.sat.python import cp_model

from app.contract import parse_input
from app.model.constraints import add_hard_constraints
from app.model.data import ModelData
from app.model.search import by_tiers, lns
from app.model.variables import DomainOptions, build_variables
from app.model.verify import hard_violations
from tests.conftest import fixtures_dir
from tests.instances import campus, group, instance, model_data, room


def fixture(name: str):
    return parse_input(json.loads((fixtures_dir() / "v1" / name / "input.json").read_text()))


@pytest.mark.parametrize("search", [by_tiers, lns], ids=["niveles", "vecindarios"])
@pytest.mark.parametrize("name", ["tiny", "small", "medium"])
def test_la_busqueda_programa_todo_el_fixture_sin_duras(search, name):
    """En `medium` el primer nivel no alcanza (un docente con dos grupos de 5 horas que no puede un día): las dos
    búsquedas tienen que darle otro nivel a quien no quedó."""
    data = fixture(name)
    result = search(ModelData.of(data), time_limit=20, workers=1, seed=7)

    assert result.unplaced == []
    assert result.first_feasible_seconds is not None
    assert hard_violations(data, result.all_sessions) == []
    assert set(result.sessions) == {group.id for group in data.groups if group.movable}


@pytest.mark.parametrize("search", [by_tiers, lns], ids=["niveles", "vecindarios"])
def test_en_el_fixture_infactible_quedan_los_que_no_caben(search):
    """`infeasible`: al menos dos de los tres grupos de t001 quedan sin programar, y lo programado no tiene duras."""
    data = fixture("infeasible")
    result = search(ModelData.of(data), time_limit=10, workers=1, seed=7)

    assert len(result.unplaced) >= 2
    assert set(result.unplaced) <= {"g001", "g002", "g003"}
    assert hard_violations(data, result.all_sessions) == []


def test_la_asignacion_relajada_deja_fuera_al_grupo_que_no_cabe():
    """Con «a lo más una opción», un grupo sin opciones no vuelve infactible el modelo: queda fuera y los demás se
    programan."""
    data = model_data(
        instance(
            campuses=[campus(days=[1], day_end="08:00")],
            groups=[group("cabe", 1), group("no-cabe", 1, capacity=99)],
            rooms=[room("r1")],
        )
    )
    model = cp_model.CpModel()
    variables = build_variables(model, data, DomainOptions())
    add_hard_constraints(model, variables, data, relaxed=True)
    model.maximize(sum(option.literal for domain in variables.groups.values() for option in domain.options))
    solver = cp_model.CpSolver()

    assert solver.solve(model) == cp_model.OPTIMAL
    assert solver.objective_value == 1
