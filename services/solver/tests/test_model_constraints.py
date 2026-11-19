"""Restricciones duras del modelo CP-SAT, una por una (tarea 7.5).

Cada regla con una instancia mínima que la rompe: con la regla el modelo es infactible y sin ella, factible. La
rejilla de casi todas es un solo bloque (lunes de 07:00 a 08:00), así que un grupo de una hora tiene una sola opción.
"""

import pytest
from ortools.sat.python import cp_model

from app.model.constraints import RULES, RuleKind, input_problems
from app.model.data import HARD_RULES
from app.model.phases import solve_hard
from tests.instances import campus, group, instance, model_data, room, session, slot, teacher

ONE_SLOT = [campus(days=[1], day_end="08:00")]
FEASIBLE = (cp_model.OPTIMAL, cp_model.FEASIBLE)


def status_of(data: dict, without: tuple[str, ...] = (), exact: bool = False) -> int:
    """El estado del modelo duro: en dos fases con niveles por necesidad (por omisión) o el exacto de 7.5."""
    data = {**data, "hard_rules": [rule for rule in HARD_RULES if rule not in without]}
    run = solve_hard(model_data(data), time_limit=5, pool_rooms=not exact, tiers_by_need=not exact)

    return run.status


CASES = {
    "HC-01": instance(
        campuses=ONE_SLOT,
        groups=[group("g1", 1, teacher_id="t1"), group("g2", 1, teacher_id="t1")],
        teachers=[teacher("t1")],
        rooms=[room("r1"), room("r2")],
    ),
    "HC-01 con un grupo fijo": instance(
        campuses=ONE_SLOT,
        groups=[
            group("fijo", 1, teacher_id="t1", movable=False, fixed_sessions=[session(1, "07:00", "08:00")]),
            group("g1", 1, teacher_id="t1"),
        ],
        teachers=[teacher("t1")],
        rooms=[room("r1")],
    ),
    "HC-01 entre campus con rejillas distintas": instance(
        campuses=[
            campus(days=[1], day_end="08:00"),
            campus("C2", days=[1], block_minutes=30, day_start="07:30", day_end="08:30"),
        ],
        groups=[group("g1", 1, teacher_id="t1"), group("g2", 1, teacher_id="t1", campus_code="C2")],
        teachers=[teacher("t1")],
        rooms=[room("r1"), room("r2", campus_code="C2")],
    ),
    "HC-02": instance(
        campuses=ONE_SLOT,
        groups=[group("g1", 1, teacher_id="t1"), group("g2", 1, teacher_id="t2")],
        teachers=[teacher("t1"), teacher("t2")],
        rooms=[room("r1")],
    ),
    "HC-02 con la ocupación de fuera": instance(
        campuses=ONE_SLOT,
        groups=[group("g1", 1)],
        rooms=[room("r1", busy=[slot(1, "07:30", "08:30")])],
    ),
    "HC-03": instance(
        campuses=ONE_SLOT,
        groups=[group("g1", 1, cohorts=["plan:p001:1:C1"]), group("g2", 1, cohorts=["plan:p001:1:C1"])],
        rooms=[room("r1"), room("r2")],
    ),
    "HC-03 con un paquete fijo": instance(
        campuses=ONE_SLOT,
        groups=[
            group("fijo", 1, cohorts=["package:k1:C1:1"], movable=False, fixed_sessions=[session(1, "07:00", "08:00")]),
            group("g1", 1, cohorts=["package:k1:C1:1"]),
        ],
        rooms=[room("r1")],
    ),
    "HC-05": instance(campuses=ONE_SLOT, groups=[group("g1", 1, capacity=30)], rooms=[room("r1", capacity=20)]),
    "HC-06": instance(
        campuses=ONE_SLOT,
        groups=[group("g1", 1, needs_lab=True, practice_hours=1)],
        rooms=[room("r1")],
    ),
    "HC-06 con un solo laboratorio para dos grupos": instance(
        campuses=ONE_SLOT,
        groups=[
            group("g1", 1, needs_lab=True, practice_hours=1),
            group("g2", 1, needs_lab=True, practice_hours=1),
        ],
        rooms=[room("lab", type="sala_computo"), room("r1")],
    ),
    "HC-09": instance(
        campuses=ONE_SLOT,
        groups=[group("g1", 1, teacher_id="t1")],
        teachers=[teacher("t1", unavailable=[slot(1, "07:30", "09:00")])],
        rooms=[room("r1")],
    ),
    "HC-10": instance(
        campuses=ONE_SLOT,
        groups=[group("g1", 1)],
        rooms=[room("ajeno", owner_department_id="d999")],
        agreements=[
            {"room_id": "ajeno", "to_department_id": "d001", "day": 1, "starts_at": "07:00", "ends_at": "07:30"}
        ],
    ),
    "HC-11": instance(
        campuses=ONE_SLOT, groups=[group("g1", 1)], rooms=[room("r1", blocks=[slot(1, "06:00", "07:01")])]
    ),
    "HC-12": instance(
        campuses=[campus(days=[1], block_minutes=50, day_end="08:40")],
        groups=[group("g1", 1)],
        rooms=[room("r1")],
    ),
}


@pytest.mark.parametrize("exact", [False, True], ids=["dos fases", "exacto"])
@pytest.mark.parametrize("case", list(CASES))
def test_la_instancia_que_rompe_la_regla_es_infactible_y_sin_la_regla_factible(case, exact):
    rule = case[:5]

    assert status_of(CASES[case], exact=exact) == cp_model.INFEASIBLE
    assert status_of(CASES[case], without=(rule,), exact=exact) in FEASIBLE


def test_hc04_es_la_rejilla_y_no_se_desactiva():
    """HC-04 no tiene variables fuera de la jornada que desactivar: el dominio es la rejilla, como en la heurística.
    Un grupo de 2 horas en una jornada de un bloque no cabe con la regla ni sin ella."""
    data = instance(campuses=ONE_SLOT, groups=[group("g1", 2)], rooms=[room("r1")])

    assert RULES["HC-04"].kind is RuleKind.DOMAIN
    assert status_of(data) == cp_model.INFEASIBLE
    assert status_of(data, without=("HC-04",)) == cp_model.INFEASIBLE


def test_hc10_un_prestamo_que_cubre_la_sesion_abre_el_salon_ajeno():
    data = instance(
        campuses=ONE_SLOT,
        groups=[group("g1", 1)],
        rooms=[room("ajeno", owner_department_id="d999")],
        agreements=[
            {"room_id": "ajeno", "to_department_id": "d001", "day": 1, "starts_at": "07:00", "ends_at": "07:30"},
            {"room_id": "ajeno", "to_department_id": "d001", "day": 1, "starts_at": "07:30", "ends_at": "08:00"},
        ],
    )

    assert status_of(data) in FEASIBLE


def test_hc12_la_tolerancia_admite_horas_que_la_rejilla_no_da_exactas():
    assert status_of({**CASES["HC-12"], "weekly_hours_tolerance": 10}) in FEASIBLE


@pytest.mark.parametrize("rule", ["HC-07", "HC-08", "HC-13"])
def test_las_reglas_de_la_entrada_no_cambian_el_modelo(rule):
    """HC-07, HC-08 y HC-13 no dependen del acomodo: no vuelven infactible el modelo (PS-08, `INPUT_RULES`)."""
    data = instance(
        groups=[group("g1", 2, teacher_id="t1")],
        teachers=[teacher("t1", remaining_hours=1)],
        rooms=[room("r1")],
    )

    assert RULES[rule].kind is RuleKind.INPUT
    assert status_of(data) in FEASIBLE
    assert status_of(data, without=(rule,)) in FEASIBLE


def test_hc08_se_reporta_como_problema_de_la_entrada_en_cada_grupo_movible_del_docente():
    """Al docente le quedan 3 h más las 2 de su grupo fijo (5) y lleva 2 + 2 + 2: se pasa por 1 h."""
    data = instance(
        groups=[
            group("fijo", 2, teacher_id="t1", movable=False, fixed_sessions=[session(1, "07:00", "08:00")]),
            group("g1", 2, teacher_id="t1"),
            group("g2", 2, teacher_id="t1"),
            group("g3", 2, teacher_id="t2"),
        ],
        teachers=[teacher("t1", remaining_hours=3), teacher("t2", remaining_hours=2)],
        rooms=[room("r1")],
    )
    problems = input_problems(model_data(data))

    assert [(problem.rule, problem.group_ids, problem.teacher_id) for problem in problems] == [
        ("HC-08", ["g1"], "t1"),
        ("HC-08", ["g2"], "t1"),
    ]
    assert problems[0].message == "El docente suma 6 h y su límite es 5 h: se pasa por 1 h."
    assert input_problems(model_data({**data, "hard_rules": ["HC-01"]})) == []


def test_cada_regla_dura_tiene_su_forma_en_el_modelo():
    assert list(RULES) == list(HARD_RULES)
    assert {rule for rule, entry in RULES.items() if entry.kind is RuleKind.CONSTRAINT} == {
        "HC-01",
        "HC-02",
        "HC-03",
        "HC-06",
    }
    assert all((entry.add is not None) == (entry.kind is RuleKind.CONSTRAINT) for entry in RULES.values())
