"""El puntaje blando en Python (`score.py`), espejo de `SoftScorer` de PHP: una solución a mano por regla, con su
puntaje calculado a mano.

La rejilla es la de `instances.campus()` con más horas: lunes y martes de 07:00 a 14:00, bloques de 60 min. Cada
prueba pone el peso de su regla y deja en cero las demás, salvo las que comparan.
"""

from pathlib import Path
from typing import Any

import pytest

from app.contract import SOFT_RULES, parse_input, parse_output
from app.contract.output import ProposedSession
from app.model.score import SoftScorer, hours, uncovered
from app.model.verify import hard_violations
from tests.conftest import fixtures_dir, load
from tests.instances import campus, group, instance, room, session, slot, teacher

DAY = [campus(day_end="14:00")]


def weights(**values: int) -> dict[str, int]:
    return {rule: values.get(rule.replace("-", "_").lower(), 0) for rule in SOFT_RULES}


def proposed(group_id: str, *slots: tuple, room_id: str | None = "r1", kind: str = "teoria") -> list[ProposedSession]:
    """Sesiones de una hora del grupo: (día, "HH:MM") cada una."""
    result = []
    for day, start in slots:
        end = f"{int(start[:2]) + 1:02d}:{start[3:]}"
        result.append(
            ProposedSession(
                group_id=group_id, day=day, starts_at=start, ends_at=end, room_id=room_id, kind=kind, changed=True
            )
        )

    return result


def score(data: dict[str, Any], sessions: list[ProposedSession]):
    return SoftScorer(parse_input(data)).score(sessions)


def test_las_horas_cuentan_una_fraccion_como_hora_y_lo_no_cubierto_va_en_minutos():
    """`Timeline::hours` y `Timeline::uncovered`."""
    assert [hours(m) for m in (0, -5, 1, 59, 60, 61, 120)] == [0, 0, 1, 1, 1, 2, 2]
    assert uncovered(1, 420, 480, [(1, 450, 480)]) == 30
    assert uncovered(1, 420, 480, [(2, 420, 480)]) == 60
    assert uncovered(1, 420, 540, [(1, 400, 430), (1, 425, 450), (1, 500, 520)]) == 70


def test_sc01_cuenta_los_huecos_de_la_cohorte_salvo_entre_dos_fijos():
    """SC-01: la cohorte tiene dos grupos fijos de otra carga (07:00 y 11:00 el lunes) y uno movible. Con el movible a
    las 09:00 hay dos huecos de una hora que lo tocan: 2. A las 12:00 el hueco de 08:00 a 11:00 queda entre dos fijos
    y no cuenta, y el movible queda pegado: 0. A las 13:00, una hora: 1."""
    data = instance(
        campuses=DAY,
        groups=[
            group("f1", 1, movable=False, cohorts=["plan:p1:1:C1"], fixed_sessions=[session(1, "07:00", "08:00")]),
            group("f2", 1, movable=False, cohorts=["plan:p1:1:C1"], fixed_sessions=[session(1, "11:00", "12:00")]),
            group("g1", 1, cohorts=["plan:p1:1:C1"]),
        ],
        soft_weights=weights(sc_01=10),
    )

    assert score(data, proposed("g1", (1, "09:00"))).by_rule["SC-01"] == 20
    assert score(data, proposed("g1", (1, "12:00"))).by_rule["SC-01"] == 0
    assert score(data, proposed("g1", (1, "13:00"))).units["SC-01"] == 1


def test_sc02_cuenta_las_horas_de_mas_en_un_dia():
    """SC-02: 3 horas en dos días: el reparto parejo es 2 por día. Las 3 el lunes suman 1; 2 y 1, nada."""
    data = instance(campuses=DAY, groups=[group("g1", 3)], soft_weights=weights(sc_02=6))

    assert score(data, proposed("g1", (1, "07:00"), (1, "08:00"), (1, "09:00"))).by_rule["SC-02"] == 6
    assert score(data, proposed("g1", (1, "07:00"), (1, "08:00"), (2, "07:00"))).by_rule["SC-02"] == 0


def test_sc03_cuenta_las_horas_fuera_del_salon_por_defecto():
    """SC-03: la materia tiene a r2 por defecto en C1; dos horas en r1 son 2, en r2 nada. Una materia sin salón por
    defecto no se penaliza."""
    data = instance(
        campuses=DAY,
        groups=[group("g1", 2), group("g2", 2)],
        rooms=[room("r1"), room("r2")],
        subject_room_defaults=[{"subject_code": "MAT-g1", "campus_code": "C1", "room_id": "r2", "priority": 1}],
        soft_weights=weights(sc_03=5),
    )
    other = proposed("g2", (1, "09:00"), (2, "09:00"))

    assert score(data, proposed("g1", (1, "07:00"), (2, "07:00")) + other).by_rule["SC-03"] == 10
    assert score(data, proposed("g1", (1, "07:00"), (2, "07:00"), room_id="r2") + other).by_rule["SC-03"] == 0


def test_sc04_y_sc08_cuentan_las_horas_fuera_de_los_preferidos():
    """SC-04: el docente prefiere el lunes de 07:30 a 09:00. Lunes 07:00 deja 30 min fuera (cuenta 1 hora), martes
    07:00 queda fuera (1): 2. SC-08 cuenta lo mismo porque es de honorarios; el otro docente no declaró preferidos."""
    data = instance(
        campuses=DAY,
        groups=[group("g1", 2, teacher_id="t1"), group("g2", 2, teacher_id="t2")],
        teachers=[teacher("t1", honorarios=True, preferred=[slot(1, "07:30", "09:00")]), teacher("t2")],
        soft_weights=weights(sc_04=4, sc_08=2),
    )
    result = score(data, proposed("g1", (1, "07:00"), (2, "07:00")) + proposed("g2", (1, "09:00"), (2, "09:00")))

    assert (result.units["SC-04"], result.units["SC-08"]) == (2, 2)
    assert (result.by_rule["SC-04"], result.by_rule["SC-08"]) == (8, 4)


def test_sc05_cuenta_los_huecos_del_docente_con_su_ocupacion_de_fuera():
    """SC-05: el docente tiene clase fuera de la corrida el lunes de 12:00 a 13:00 en C2. Su grupo a las 07:00 deja un
    hueco de 4 horas hasta esa clase (cuenta, porque toca al grupo); el martes no hay nada más: 4."""
    data = instance(
        campuses=[*DAY, campus("C2", day_end="14:00")],
        groups=[group("g1", 2, teacher_id="t1")],
        teachers=[teacher("t1", busy=[slot(1, "12:00", "13:00", campus_code="C2")])],
        soft_weights=weights(sc_05=3),
    )

    assert score(data, proposed("g1", (1, "07:00"), (2, "07:00"))).by_rule["SC-05"] == 12


def test_sc06_cuenta_los_cambios_de_edificio_entre_sesiones_pegadas():
    """SC-06: dos grupos del docente pegados el lunes (07:00 y 08:00), en salones de edificios distintos: 1. En el
    mismo edificio, o con una hora libre en medio, nada."""
    data = instance(
        campuses=DAY,
        groups=[group("g1", 1, teacher_id="t1"), group("g2", 1, teacher_id="t1")],
        teachers=[teacher("t1")],
        rooms=[room("r1", building_id="b1"), room("r2", building_id="b2"), room("r3", building_id="b1")],
        soft_weights=weights(sc_06=2),
    )
    first = proposed("g1", (1, "07:00"), room_id="r1")

    assert score(data, first + proposed("g2", (1, "08:00"), room_id="r2")).by_rule["SC-06"] == 2
    assert score(data, first + proposed("g2", (1, "08:00"), room_id="r3")).by_rule["SC-06"] == 0
    assert score(data, first + proposed("g2", (1, "09:00"), room_id="r2")).by_rule["SC-06"] == 0


def test_sc07_cuenta_las_horas_de_empalme_entre_semestres_seguidos_una_vez_por_par():
    """SC-07: g1 es el grupo único de primer semestre y g2 el de segundo del mismo plan y campus; se empalman lunes y
    martes a las 07:00: 2. El de tercero (g3) se empalma con g2 el lunes: 1 más. g1 y g3 no son seguidos."""
    data = instance(
        campuses=DAY,
        groups=[
            group("g1", 2, cohorts=["plan:p1:1:C1"]),
            group("g2", 2, cohorts=["plan:p1:2:C1"]),
            group("g3", 1, cohorts=["plan:p1:3:C1"]),
        ],
        soft_weights=weights(sc_07=3),
    )
    sessions = (
        proposed("g1", (1, "07:00"), (2, "07:00"))
        + proposed("g2", (1, "07:00"), (2, "07:00"))
        + proposed("g3", (1, "07:00"))
    )

    assert score(data, sessions).units["SC-07"] == 3


def test_sc09_cuenta_el_traslado_justo_entre_campus():
    """SC-09: el docente sale de C1 a las 08:00 y tiene clase fuera de la corrida en C2 a las 08:00 (0 min de
    traslado, el mínimo es 60): 1. Si sale a las 09:00 no son seguidas al revés (C2 termina 09:00 y la de C1 empieza
    09:00): también 1. Con dos horas libres, nada."""
    data = instance(
        campuses=[*DAY, campus("C2", day_end="14:00")],
        groups=[group("g1", 1, teacher_id="t1")],
        teachers=[teacher("t1", busy=[slot(1, "08:00", "09:00", campus_code="C2")])],
        soft_weights=weights(sc_09=2),
    )

    assert score(data, proposed("g1", (1, "07:00"))).by_rule["SC-09"] == 2
    assert score(data, proposed("g1", (1, "09:00"))).by_rule["SC-09"] == 2
    assert score(data, proposed("g1", (1, "11:00"))).by_rule["SC-09"] == 0


@pytest.mark.parametrize("rule", SOFT_RULES)
def test_un_peso_cero_apaga_la_regla(rule):
    """Con todas las reglas en 10 la solución penaliza en cada una; con la regla en 0, esa da cero y el total baja
    justo lo que daba."""
    data = everything_instance()
    sessions = everything_solution()
    full = score(data, sessions)
    off = score({**data, "soft_weights": {**data["soft_weights"], rule: 0}}, sessions)

    assert full.by_rule[rule] > 0
    assert off.by_rule[rule] == 0
    assert off.units[rule] == 0
    assert full.total - off.total == full.by_rule[rule]


def test_una_solucion_que_penaliza_en_las_nueve_reglas_da_lo_calculado_a_mano():
    """Ver `everything_solution`: cada regla con su cuenta a mano, con peso 10."""
    result = score(everything_instance(), everything_solution())

    assert result.units == {
        "SC-01": 2,
        "SC-02": 1,
        "SC-03": 3,
        "SC-04": 4,
        "SC-05": 2,
        "SC-06": 1,
        "SC-07": 1,
        "SC-08": 4,
        "SC-09": 1,
    }
    assert result.total == 190
    assert result.total == sum(result.by_rule.values())
    assert all(result.by_rule[rule] == 10 * result.units[rule] for rule in SOFT_RULES)
    assert result.to_contract().total == result.total


def everything_instance() -> dict[str, Any]:
    """Tres grupos del docente t1 (de honorarios, prefiere el martes de 07:00 a 08:00, tiene clase fuera de la corrida
    el lunes de 11:00 a 12:00 en C2 y el martes de 10:00 a 11:00 en C1) y uno sin docente; la materia de g1 tiene a r2
    por defecto."""
    return instance(
        campuses=[*DAY, campus("C2", day_end="14:00")],
        groups=[
            group("g1", 3, teacher_id="t1", cohorts=["plan:p1:1:C1"]),
            group("g2", 1, teacher_id="t1", cohorts=["plan:p1:2:C1"]),
            group("g3", 1, teacher_id="t1", cohorts=["plan:p1:1:C1"]),
            group("g4", 2, cohorts=["plan:p1:1:C1"]),
        ],
        teachers=[
            teacher(
                "t1",
                honorarios=True,
                preferred=[slot(2, "07:00", "08:00")],
                busy=[slot(1, "11:00", "12:00", campus_code="C2"), slot(2, "10:00", "11:00", campus_code="C1")],
            )
        ],
        rooms=[room("r1", building_id="b1"), room("r2", building_id="b2")],
        subject_room_defaults=[{"subject_code": "MAT-g1", "campus_code": "C1", "room_id": "r2", "priority": 1}],
        soft_weights=dict.fromkeys(SOFT_RULES, 10),
    )


def everything_solution() -> list[ProposedSession]:
    """Lunes: g1 de 07:00 a 10:00 en r1 (b1), g3 de 10:00 a 11:00 en r2 (b2) y g4 de 13:00 a 14:00. Martes: g2 y g4 a
    las 07:00.

    - SC-01: la cohorte de primero (g1, g3, g4) tiene libre el lunes de 11:00 a 13:00: 2.
    - SC-02: g1 lleva 3 horas el lunes y el reparto parejo es 2: 1.
    - SC-03: las 3 horas de g1 van en r1 y no en r2: 3.
    - SC-04 y SC-08: g1 y g3 caen fuera del martes de 07:00 a 08:00; g2 no: 4 cada una.
    - SC-05: el martes el docente da g2 hasta las 08:00 y su clase de fuera empieza a las 10:00: 2.
    - SC-06: g1 en b1 termina a las 10:00 y g3 en b2 empieza a las 10:00: 1.
    - SC-07: g2 (segundo) y g4 (primero) se empalman el martes a las 07:00: 1.
    - SC-09: el lunes g3 termina en C1 a las 11:00 y la clase de fuera empieza en C2 a las 11:00: 1.
    """
    return (
        proposed("g1", (1, "07:00"), (1, "08:00"), (1, "09:00"), room_id="r1")
        + proposed("g3", (1, "10:00"), room_id="r2")
        + proposed("g4", (1, "13:00"), (2, "07:00"), room_id="r1")
        + proposed("g2", (2, "07:00"), room_id="r2")
    )


def saved_outputs() -> list[tuple[Path, Path]]:
    """Las salidas guardadas para que PHP las evalúe (`tests/Unit/Solver/CpSatScoreTest.php`): las de CP-SAT de los
    fixtures (`cpsat-output.json`) y las soluciones a mano de `scores/`."""
    root = fixtures_dir()
    cases = [(path.parent / "input.json", path) for path in sorted((root / "v1").glob("*/cpsat-output.json"))]
    cases += [(folder / "input.json", folder / "output.json") for folder in sorted((root / "scores").glob("*/"))]

    return cases


SAVED = saved_outputs()


@pytest.mark.parametrize(("input_path", "output_path"), SAVED, ids=[path.parent.name for _, path in SAVED])
def test_el_puntaje_de_cada_salida_guardada_es_el_de_score_py(input_path: Path, output_path: Path):
    """PHP revisa que el mismo `soft_score` sea el de su evaluador: juntas, las dos pruebas dicen que `score.py` da el
    número de PHP."""
    data = parse_input(load(input_path))
    output = parse_output(load(output_path))

    assert SoftScorer(data).score(output.sessions).to_contract() == output.soft_score
    assert output.soft_score.total == sum(value for value in output.soft_score.by_rule.model_dump().values())


def test_hay_salidas_de_cp_sat_de_small_y_medium_y_la_solucion_a_mano_es_la_de_estas_pruebas():
    assert {path.parent.name for _, path in SAVED} >= {"small", "medium", "all-rules"}
    folder = fixtures_dir() / "scores" / "all-rules"
    assert parse_input(load(folder / "input.json")) == parse_input({**everything_instance(), "run_id": "all-rules"})
    assert parse_output(load(folder / "output.json")).sessions == everything_solution()


@pytest.mark.parametrize("name", ["small", "medium"])
def test_las_salidas_de_cp_sat_guardadas_programan_todo_sin_duras(name: str):
    folder = fixtures_dir() / "v1" / name
    data = parse_input(load(folder / "input.json"))
    output = parse_output(load(folder / "cpsat-output.json"))
    expected = load(folder / "expected.json")

    assert hard_violations(data, output.sessions) == []
    assert set(expected["must_schedule"]) <= {session.group_id for session in output.sessions}
    assert output.unscheduled == []
