"""La búsqueda de una primera solución sin duras (`search.py`): arranque tibio y vecindarios (LNS)."""

import json

import pytest
from ortools.sat.python import cp_model

from app.contract import parse_input
from app.model.constraints import add_hard_constraints
from app.model.data import ModelData
from app.model.search import lns
from app.model.variables import DomainOptions, build_variables
from app.model.verify import hard_violations
from tests.conftest import fixtures_dir
from tests.instances import campus, group, instance, model_data, room


def fixture(name: str):
    return parse_input(json.loads((fixtures_dir() / "v1" / name / "input.json").read_text()))


@pytest.mark.parametrize("name", ["tiny", "small", "medium"])
def test_la_busqueda_programa_todo_el_fixture_sin_duras(name):
    """En `medium` la primera vuelta no alcanza: los vecindarios le dan los otros niveles a quien no quedó."""
    data = fixture(name)
    result = lns(ModelData.of(data), time_limit=20, workers=1, seed=7)

    assert result.unplaced == []
    assert result.first_feasible_seconds is not None
    assert hard_violations(data, result.all_sessions) == []
    assert set(result.sessions) == {group.id for group in data.groups if group.movable}


def test_en_el_fixture_infactible_quedan_los_que_no_caben():
    """`infeasible`: al menos dos de los tres grupos de t001 quedan sin programar, y lo programado no tiene duras."""
    data = fixture("infeasible")
    result = lns(ModelData.of(data), time_limit=10, workers=1, seed=7)

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


def test_la_pista_es_el_horario_actual_cuando_es_una_opcion_del_patron():
    """PS-07: un grupo de 2 horas que hoy va lunes y martes a las 08:00 da esa opción como pista; uno que va a horas
    distintas cada día no da pista. La pista solo orienta la búsqueda: quedarse en la hora actual es del objetivo
    (7.6)."""
    from app.model.search import _current_options
    from tests.instances import session

    data = model_data(
        instance(
            groups=[
                group("patron", 2, current_sessions=[session(1, "08:00", "09:00"), session(2, "08:00", "09:00")]),
                group("otro", 2, current_sessions=[session(1, "07:00", "08:00"), session(2, "09:00", "10:00")]),
            ],
            rooms=[room("r1"), room("r2")],
        )
    )

    assert _current_options(data) == {"patron": (((1, 1), (2, 1)), 1)}
