"""Variables del modelo CP-SAT y su dominio pre-filtrado (tarea 7.4)."""

import json

import pytest
from ortools.sat.python import cp_model

from app.contract import parse_input
from app.engine import RunContext
from app.engine.base import RunCancelled
from app.model.data import ModelData
from app.model.pattern import Grid, tiers
from app.model.variables import (
    BuildBudget,
    BuildTimeout,
    DomainOptions,
    ModelTooLarge,
    Variables,
    build_variables,
    unit_counts,
)
from tests.conftest import fixtures_dir
from tests.instances import campus, group, instance, model_data, room, session, slot, teacher

COHORT = "plan:p001:1:C1"


#: El dominio exacto de 7.4: un `z` por ventana y salón y todos los niveles del patrón.
EXACT = DomainOptions(pool_rooms=False, all_tiers=True)


def build(data: dict, options: DomainOptions = EXACT) -> tuple[cp_model.CpModel, Variables]:
    model = cp_model.CpModel()

    return model, build_variables(model, model_data(data), options)


def windows_of(variables: Variables, group_id: str) -> set[tuple[int, int, int]]:
    return set(variables.groups[group_id].windows)


def rooms_by_window(variables: Variables, group_id: str) -> dict[tuple[int, int, int], set[str]]:
    return {key: set(window.rooms) for key, window in variables.groups[group_id].windows.items()}


def test_el_patron_sigue_los_niveles_de_la_heuristica():
    """Los mismos niveles que SessionPattern::tiers en PHP (PS-01 a PS-04)."""
    week = [1, 2, 3, 4, 5]

    assert tiers(4, week)[0] == [((1, 1), (2, 1), (3, 1), (4, 1))]
    assert [tuple(day for day, _ in option) for option in tiers(4, week)[1]] == [
        (1, 2, 3, 5),
        (1, 2, 4, 5),
        (1, 3, 4, 5),
        (2, 3, 4, 5),
    ]
    assert tiers(5, week)[0] == [((1, 1), (2, 1), (3, 1), (4, 1), (5, 1))]
    # 5 horas en 4 días: un día de 2 horas, las de sobra primero en los días más tempranos.
    assert tiers(5, week)[1][0] == ((1, 2), (2, 1), (3, 1), (4, 1))
    assert tiers(6, [1, 2, 3, 4, 5, 6])[0] == [((1, 1), (2, 1), (3, 1), (4, 1), (5, 1), (6, 1))]
    assert len(tiers(4, week)) == 4
    assert tiers(0, week) == []


def test_una_instancia_chica_crea_el_numero_de_variables_esperado():
    """Un grupo de 2 horas, lunes y martes de 07:00 a 10:00 (3 bloques), dos salones.

    Opciones: el patrón (lunes y martes, 1 hora cada uno) a las 3 horas de inicio, más lunes o martes con 2 horas
    seguidas a 2 horas de inicio cada uno: 3 + 4 = 7 `y`. Ventanas: 3 de una hora por día (6) y 2 de dos horas por día
    (4): 10, cada una con sus 2 salones: 20 `z`. Una restricción de enlace por ventana.
    """
    model, variables = build(instance(groups=[group("g1", 2)], rooms=[room("r1"), room("r2")]))

    assert variables.option_count == 7
    assert len(variables.groups["g1"].windows) == 10
    assert variables.room_count == 20
    assert len(model.proto.variables) == 27
    assert len(model.proto.constraints) == 10
    assert [option.tier for option in variables.groups["g1"].options] == [0, 0, 0, 1, 1, 1, 1]


def test_sin_salones_en_la_modalidad_no_hay_variables_de_salon():
    model, variables = build(instance(campuses=[campus(uses_rooms=False)], groups=[group("g1", 2)]))

    assert variables.option_count == 7
    assert variables.room_count == 0
    assert len(model.proto.constraints) == 0


def test_los_grupos_fijos_y_la_ocupacion_de_fuera_quedan_como_constantes():
    """El grupo fijo no tiene variables; su sesión del lunes a las 07:00 ocupa a su docente (HC-01), su cohorte
    (HC-03) y su salón (HC-02), y ningún grupo movible puede usar ese bloque con ellos."""
    data = instance(
        groups=[
            group(
                "fijo",
                movable=False,
                teacher_id="t1",
                cohorts=[COHORT],
                fixed_sessions=[session(1, "07:00", "08:00", "r1")],
            ),
            group("mismo-docente", 1, teacher_id="t1"),
            group("misma-cohorte", 1, cohorts=[COHORT]),
            group("libre", 1),
        ],
        teachers=[teacher("t1")],
        rooms=[room("r1"), room("r2")],
    )
    _, variables = build(data)

    assert "fijo" not in variables.groups
    assert (1, 0, 1) not in windows_of(variables, "mismo-docente")
    assert variables.groups["mismo-docente"].rejected_options["HC-01"] == 1
    assert (1, 0, 1) not in windows_of(variables, "misma-cohorte")
    assert variables.groups["misma-cohorte"].rejected_options["HC-03"] == 1
    assert rooms_by_window(variables, "libre")[(1, 0, 1)] == {"r2"}
    assert rooms_by_window(variables, "libre")[(1, 1, 1)] == {"r1", "r2"}
    assert variables.groups["libre"].rejected_rooms["HC-02"] == 1


def test_la_ocupacion_de_fuera_de_la_corrida_cuenta_en_cualquier_campus():
    """`busy` de un docente en otro campus y `busy` de un salón: las dos son ocupación fija."""
    data = instance(
        campuses=[campus(), campus("C2")],
        groups=[group("g1", 1, teacher_id="t1")],
        teachers=[teacher("t1", busy=[slot(2, "07:30", "08:30", campus_code="C2")])],
        rooms=[room("r1", busy=[slot(1, "09:00", "10:00")]), room("r2")],
    )
    _, variables = build(data)

    assert windows_of(variables, "g1") == {(1, 0, 1), (1, 1, 1), (1, 2, 1), (2, 2, 1)}
    assert rooms_by_window(variables, "g1")[(1, 2, 1)] == {"r2"}


@pytest.mark.parametrize(
    ("rule", "overrides", "expected_windows", "expected_rooms"),
    [
        pytest.param(
            "HC-09",
            {"teachers": [teacher("t1", unavailable=[slot(1, "07:00", "08:30")])]},
            {(1, 2, 1), (2, 0, 1), (2, 1, 1), (2, 2, 1)},
            None,
            id="indisponibilidad del docente",
        ),
        pytest.param(
            "HC-05",
            {"rooms": [room("r1"), room("chico", capacity=20)]},
            None,
            {"r1"},
            id="capacidad del salón",
        ),
        pytest.param(
            "HC-11",
            {"rooms": [room("r1"), room("bloqueado", blocks=[slot(1, "08:00", "09:00")])]},
            None,
            {"r1", "bloqueado"},
            id="bloqueo del salón",
        ),
        pytest.param(
            "HC-10",
            {"rooms": [room("r1"), room("ajeno", owner_department_id="d999")]},
            None,
            {"r1"},
            id="salón de otro departamento sin préstamo",
        ),
    ],
)
def test_el_prefiltro_descarta_lo_que_la_regla_impide(rule, overrides, expected_windows, expected_rooms):
    data = instance(groups=[group("g1", 1, teacher_id="t1")], teachers=[teacher("t1")], rooms=[room("r1")])
    data.update(overrides)
    _, variables = build(data)
    domain = variables.groups["g1"]

    if expected_windows is not None:
        assert set(domain.windows) == expected_windows
        assert domain.rejected_options[rule] == 2
    if expected_rooms is not None:
        assert set().union(*rooms_by_window(variables, "g1").values()) == expected_rooms
        assert domain.rejected_rooms[rule] > 0
    if rule == "HC-11":
        assert rooms_by_window(variables, "g1")[(1, 1, 1)] == {"r1"}

    # Sin la regla, la combinación vuelve al dominio.
    data["hard_rules"] = [other for other in data["hard_rules"] if other != rule]
    _, unfiltered = build(data)
    assert len(unfiltered.groups["g1"].windows) == 6
    assert set().union(*rooms_by_window(unfiltered, "g1").values()) == {room["id"] for room in data["rooms"]}


def test_un_prestamo_aceptado_abre_el_salon_ajeno_solo_en_lo_que_cubre():
    """HC-10: el préstamo del martes de 07:00 a 08:00 y el de 08:00 a 09:00 cubren juntos dos horas (RoomLoan)."""
    data = instance(
        groups=[group("g1", 2)],
        rooms=[room("ajeno", owner_department_id="d999")],
        agreements=[
            {"room_id": "ajeno", "to_department_id": "d001", "day": 2, "starts_at": "07:00", "ends_at": "08:00"},
            {"room_id": "ajeno", "to_department_id": "d001", "day": 2, "starts_at": "08:00", "ends_at": "09:00"},
            {"room_id": "ajeno", "to_department_id": "d777", "day": 1, "starts_at": "07:00", "ends_at": "10:00"},
        ],
    )
    _, variables = build(data)

    assert windows_of(variables, "g1") == {(2, 0, 2)}


def test_solo_salones_del_campus_del_grupo_y_de_sus_candidatos():
    data = instance(
        campuses=[campus(), campus("C2")],
        groups=[group("g1", 1), group("g2", 1, candidate_room_ids=["r2"])],
        rooms=[room("r1"), room("r2"), room("otro-campus", campus_code="C2")],
    )
    _, variables = build(data)

    assert set().union(*rooms_by_window(variables, "g1").values()) == {"r1", "r2"}
    assert set().union(*rooms_by_window(variables, "g2").values()) == {"r2"}


def test_la_practica_con_laboratorio_pide_un_espacio_de_practica():
    """HC-06: sin laboratorio, taller ni sala de cómputo en el campus, el grupo no tiene opciones; con uno, solo las
    opciones donde alguna ventana tiene ese espacio libre."""
    lab_group = group("g1", 2, needs_lab=True, practice_hours=1)
    _, variables = build(instance(groups=[lab_group], rooms=[room("r1")]))

    assert variables.groups["g1"].options == []
    assert variables.groups["g1"].main_reason() == "HC-06"
    assert [domain.id for domain in variables.unplaceable()] == ["g1"]

    _, variables = build(
        instance(
            groups=[lab_group],
            rooms=[room("r1"), room("lab", type="laboratorio", blocks=[slot(1, "07:00", "10:00")])],
        )
    )
    domain = variables.groups["g1"]
    assert {(option.spread, option.block) for option in domain.options} == {
        (((1, 1), (2, 1)), 0),
        (((1, 1), (2, 1)), 1),
        (((1, 1), (2, 1)), 2),
        (((2, 2),), 0),
        (((2, 2),), 1),
    }
    assert all(option.practice == 1 for option in domain.options)


@pytest.mark.parametrize(
    ("tolerance", "rules_off", "expected"),
    [
        pytest.param(0, [], [], id="sin tolerancia no hay unidades exactas"),
        pytest.param(10, [], [1], id="una unidad de 50 min queda a 10 min de la hora"),
        pytest.param(40, [], [1, 2], id="dos unidades quedan a 40 min"),
        pytest.param(0, ["HC-12"], [1, 2], id="sin HC-12, las dos más cercanas"),
    ],
)
def test_las_horas_semanales_siguen_la_rejilla_y_la_tolerancia(tolerance, rules_off, expected):
    """HC-12 y PS-06: con bloques de 50 min una hora no da unidades exactas."""
    data = instance(
        campuses=[campus(block_minutes=50, day_end="10:20")],
        groups=[group("g1", 1)],
        weekly_hours_tolerance=tolerance,
    )
    data["hard_rules"] = [rule for rule in data["hard_rules"] if rule not in rules_off]
    model_input = model_data(data)
    grid = model_input.grids["C1"]

    assert unit_counts(model_input.groups["g1"], grid, model_input) == expected


def test_el_nivel_sigue_de_una_cantidad_de_unidades_a_la_otra():
    """Sin HC-12 y con bloques de 50 min, una hora se da con 1 o 2 bloques: el patrón de la cantidad más cercana (1
    bloque, a 10 min) es el nivel 0 y el de la otra va después de todos los niveles de la primera."""
    data = instance(campuses=[campus(block_minutes=50, day_end="10:20")], groups=[group("g1", 1)], rooms=[room("r1")])
    data["hard_rules"] = [rule for rule in data["hard_rules"] if rule != "HC-12"]
    _, variables = build(data)
    tiers_by_units = {}
    for option in variables.groups["g1"].options:
        units = sum(count for _, count in option.spread)
        tiers_by_units.setdefault(units, set()).add(option.tier)

    assert tiers_by_units == {1: {0, 1}, 2: {2, 3}}


def test_las_ventanas_caen_en_la_rejilla_de_su_campus():
    """HC-04 por construcción: días del campus, dentro de la jornada y alineadas a sus bloques."""
    data = ModelData.of(parse_input(json.loads((fixtures_dir() / "v1" / "medium" / "input.json").read_text())))
    variables = build_variables(cp_model.CpModel(), data)

    for domain in variables.groups.values():
        grid: Grid = domain.grid
        for window in domain.windows.values():
            assert window.day in grid.days
            assert grid.start <= window.start < window.end <= grid.end
            assert (window.start - grid.start) % grid.block_minutes == 0
            assert window.end - window.start == window.units * grid.unit_minutes


def test_la_ocupacion_da_los_literales_de_cada_inicio_de_ventana():
    """Dos grupos del mismo docente: en cada inicio donde coinciden, los literales de los dos."""
    data = instance(
        groups=[group("g1", 1, teacher_id="t1"), group("g2", 1, teacher_id="t1"), group("g3", 1, teacher_id="t2")],
        teachers=[teacher("t1"), teacher("t2")],
        rooms=[room("r1")],
    )
    _, variables = build(data)
    cliques = [(key, day, minute, len(literals)) for key, day, minute, literals in variables.teachers.cliques()]

    assert sorted(cliques) == [("t1", day, minute, 2) for day in (1, 2) for minute in (420, 480, 540)]
    assert len(variables.teachers.at("t2", 1, 420)) == 1
    assert len(variables.rooms.at("r1", 1, 420)) == 3


def test_por_omision_cada_grupo_lleva_solo_el_primer_nivel_que_cabe():
    """Niveles por necesidad (PS-04): la misma instancia chica solo con el patrón, 3 opciones en vez de 7, y sin `z`
    porque sus ventanas de un bloque van al conjunto de salones."""
    _, variables = build(instance(groups=[group("g1", 2)], rooms=[room("r1"), room("r2")]), DomainOptions())
    domain = variables.groups["g1"]

    assert variables.option_count == 3
    assert {option.tier for option in domain.options} == {0}
    assert domain.truncated
    assert variables.room_count == 0
    assert all(window.pooled for window in domain.windows.values())
    assert sorted(variables.pooled) == [("C1", day, block) for day in (1, 2) for block in (0, 1, 2)]


def test_si_el_patron_no_cabe_entra_el_nivel_siguiente():
    """El docente no puede el martes: el patrón (lunes y martes) no cabe y entra el nivel de un día de 2 horas, que va
    con `z` explícito porque ocupa dos bloques."""
    data = instance(
        groups=[group("g1", 2, teacher_id="t1")],
        teachers=[teacher("t1", unavailable=[slot(2, "07:00", "10:00")])],
        rooms=[room("r1"), room("r2")],
    )
    _, variables = build(data, DomainOptions())
    domain = variables.groups["g1"]

    assert {(option.spread, option.block) for option in domain.options} == {(((1, 2),), 0), (((1, 2),), 1)}
    assert not domain.truncated
    assert all(not window.pooled and set(window.rooms) == {"r1", "r2"} for window in domain.windows.values())


def test_otro_departamento_y_los_candidatos_llevan_z_explicito():
    """El conjunto de salones del campus es del departamento con más grupos; los de otro departamento y los que traen
    `candidate_room_ids` eligen salón con `z`, como antes."""
    data = instance(
        groups=[
            group("g1", 1),
            group("g2", 1),
            group("otro", 1, department_id="d777"),
            group("candidatos", 1, candidate_room_ids=["r1"]),
        ],
        rooms=[room("r1"), room("r2")],
    )
    _, variables = build(data, DomainOptions())

    assert variables.pools["C1"].department_id == "d001"
    assert all(window.pooled for window in variables.groups["g1"].windows.values())
    assert all(not window.pooled and window.rooms for window in variables.groups["otro"].windows.values())
    assert all(set(window.rooms) == {"r1"} for window in variables.groups["candidatos"].windows.values())


def test_un_grupo_con_laboratorio_del_conjunto_marca_sus_ventanas_de_practica():
    """HC-06 en dos fases: cada ventana del conjunto con un espacio de práctica libre lleva una booleana que dice si va
    en laboratorio; donde el laboratorio está bloqueado, no."""
    data = instance(
        groups=[group("g1", 2, needs_lab=True, practice_hours=1)],
        rooms=[room("r1"), room("lab", type="laboratorio", blocks=[slot(1, "07:00", "10:00")])],
    )
    _, variables = build(data, DomainOptions())
    windows = variables.groups["g1"].windows

    assert variables.lab_count == 3
    assert {key for key, window in windows.items() if window.lab is not None} == {(2, 0, 1), (2, 1, 1), (2, 2, 1)}


def test_el_presupuesto_corta_el_modelo_antes_de_pasar_el_limite_de_variables():
    """Con 26 variables de límite, la instancia chica (7 `y` + 20 `z`) falla al ir a crear sus salones, con un
    mensaje que dice qué hacer, en vez de agotar la memoria del servicio."""
    data = model_data(instance(groups=[group("g1", 2)], rooms=[room("r1"), room("r2")]))
    build_variables(cp_model.CpModel(), data, EXACT, BuildBudget(max_variables=27))

    with pytest.raises(ModelTooLarge, match="SOLVER_MAX_MODEL_VARIABLES"):
        build_variables(cp_model.CpModel(), data, EXACT, BuildBudget(max_variables=26))


def test_armar_el_modelo_revisa_la_cancelacion_y_el_plazo():
    data = model_data(instance(groups=[group("g1", 2)], rooms=[room("r1")]))
    cancelled = RunContext(is_cancelled=lambda: True)

    with pytest.raises(RunCancelled):
        build_variables(cp_model.CpModel(), data, budget=BuildBudget(check_cancelled=cancelled.check_cancelled))
    with pytest.raises(BuildTimeout):
        build_variables(cp_model.CpModel(), data, budget=BuildBudget(deadline=0.0))
