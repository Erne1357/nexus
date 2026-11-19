"""La pista de la entrada (`hint_sessions`, `app/model/hint.py`): la salida de la heurística que manda `CpSatEngine`
como solución de partida de la búsqueda y como pista de CP-SAT en `y` y `z` (docs/arquitectura/solver.md, «Pista de
la heurística»).
"""

import json
from typing import Any

import pytest
from ortools.sat.python import cp_model

from app.contract import RunStatus, SolverInput, parse_input, parse_output
from app.contract.input import HintSession
from app.contract.output import ProposedSession
from app.engine import CpSatEngine, RunContext
from app.model.data import ModelData
from app.model.hint import read_hint
from app.model.phases import SearchLimits
from app.model.score import SoftScorer
from app.model.search import Search, _add_hints, lns
from app.model.variables import DomainOptions, build_variables
from app.model.verify import hard_violations
from tests.conftest import fixtures_dir
from tests.instances import group, instance, room, session


def with_hint(data: SolverInput, sessions: list[Any]) -> SolverInput:
    """La entrada con `hint_sessions`, sin pasar por la validación del contrato (para poder darle un grupo que no
    está, como podría llegar de otra versión de PHP)."""
    hints = [
        HintSession(
            group_id=entry.group_id,
            day=entry.day,
            starts_at=entry.starts_at,
            ends_at=entry.ends_at,
            kind=entry.kind,
            room_id=entry.room_id,
        )
        for entry in sessions
    ]

    return data.model_copy(update={"hint_sessions": hints})


def two_groups() -> SolverInput:
    """Lunes y martes de 07:00 a 10:00: g1 y g2 de 2 horas (lunes y martes a la misma hora, su patrón), dos aulas de 40
    y una de 10."""
    return parse_input(
        instance(
            groups=[group("g1", 2), group("g2", 2)],
            rooms=[room("r1"), room("r2"), room("chico", capacity=10)],
        )
    )


def hinted(
    group_id: str, days: list[int], starts_at: str, ends_at: str, room_id: str | None, kind: str = "teoria"
) -> list[ProposedSession]:
    return [
        ProposedSession(
            group_id=group_id,
            day=day,
            starts_at=starts_at,
            ends_at=ends_at,
            room_id=room_id,
            kind=kind,
            changed=True,
        )
        for day in days
    ]


VALID_G1 = hinted("g1", [1, 2], "07:00", "08:00", "r1")


def saved(name: str, file: str | None = None) -> tuple[SolverInput, list[ProposedSession]]:
    """La entrada de un fixture y, si se da `file`, las sesiones de una de sus salidas guardadas."""
    folder = fixtures_dir() / "v1" / name
    data = parse_input(json.loads((folder / "input.json").read_text()))
    if file is None:
        return data, []

    return data, parse_output(json.loads((folder / file).read_text())).sessions


def tier_sum(data: SolverInput, sessions: list[ProposedSession]) -> int:
    """La suma de los niveles del patrón de una solución completa (PS-04), leída como pista."""
    hint = read_hint(ModelData.of(with_hint(data, sessions)))
    assert hint.ignored == {}

    return sum(entry.tier for entry in hint.groups.values())


def test_una_pista_valida_da_la_opcion_el_nivel_y_las_sesiones_de_cada_grupo():
    """La salida guardada de `small` como pista: los seis grupos entran, cada uno con una opción de su dominio (con
    todos los niveles) y su nivel (g003 fuera de su patrón: su docente no puede el viernes), y sus sesiones son las de
    la pista. En la solución de referencia de `tiny`, anterior al patrón, el grupo con tres horas seguidas un día no
    entra."""
    data, sessions = saved("small", "cpsat-output.json")
    model_data = ModelData.of(with_hint(data, sessions))

    hint = read_hint(model_data)

    assert hint.ignored == {}
    assert set(hint.groups) == {group.id for group in data.groups if group.movable}
    domains = build_variables(cp_model.CpModel(), model_data, DomainOptions(all_tiers=True)).groups
    tiny = read_hint(ModelData.of(with_hint(*saved("tiny", "output.json"))))
    assert tiny.ignored == {
        "0190a000-0000-7000-8000-000000000102": "sus días y horas no son una opción del patrón de sesiones"
    }
    assert [group_id for group_id, entry in hint.groups.items() if entry.tier] == ["g003"]
    for group_id, entry in hint.groups.items():
        tiers = {(option.spread, option.block): option.tier for option in domains[group_id].options}
        assert tiers[entry.chosen] == entry.tier
        expected = [(s.day, s.starts_at, s.ends_at, s.room_id, s.kind) for s in sessions if s.group_id == group_id]
        assert sorted((s.day, s.starts_at, s.ends_at, s.room_id, s.kind) for s in entry.sessions) == sorted(expected)


@pytest.mark.parametrize(
    ("g2", "reason"),
    [
        (hinted("g2", [1, 2], "09:00", "11:00", "r2"), "no cae en la rejilla"),
        (hinted("g2", [1, 2], "07:30", "08:30", "r2"), "no cae en la rejilla"),
        (hinted("g2", [1], "07:00", "10:00", "r2"), "no son una opción del patrón"),
        (hinted("g2", [1], "08:00", "09:00", "r2") + hinted("g2", [2], "09:00", "10:00", "r2"), "opción del patrón"),
        (hinted("g2", [1, 2], "08:00", "09:00", "chico"), "HC-05"),
        (hinted("g2", [1, 2], "08:00", "09:00", None), "va sin salón"),
        (hinted("g2", [1], "08:00", "09:00", "r2") + hinted("g2", [2], "08:00", "09:00", "otro"), "no es de su campus"),
        (
            hinted("g2", [1], "08:00", "09:00", "r2") + hinted("g2", [2], "08:00", "09:00", "r2", "practica"),
            "horas de práctica",
        ),
    ],
    ids=[
        "fuera de la jornada",
        "no alineada",
        "tres horas",
        "otra hora",
        "salón chico",
        "sin salón",
        "otro campus",
        "práctica de más",
    ],
)
def test_la_pista_invalida_de_un_grupo_se_ignora_con_su_motivo(g2: list[ProposedSession], reason: str):
    """Una hora fuera de la rejilla, un salón que no cabe, unas sesiones que no son opción del patrón (3 horas de un
    grupo de 2, o una hora distinta cada día) o una hora de práctica en un grupo sin práctica (el modelo nunca la
    escribiría así): la pista de g2 se ignora con su motivo y la de g1 entra."""
    hint = read_hint(ModelData.of(with_hint(two_groups(), VALID_G1 + g2)))

    assert set(hint.groups) == {"g1"}
    assert reason in hint.ignored["g2"]


def test_la_pista_de_un_grupo_que_no_esta_o_de_un_grupo_fijo_no_cuenta():
    """Un grupo que no está en la entrada se ignora con su motivo; la de un grupo fijo no hace nada (como dice el
    contrato) y no se reporta."""
    data = parse_input(
        instance(
            groups=[group("g1", 2), group("fijo", 2, movable=False, fixed_sessions=[session(1, "09:00", "10:00")])],
            rooms=[room("r1"), room("r2")],
        )
    )

    hint = read_hint(
        ModelData.of(
            with_hint(
                data,
                VALID_G1 + hinted("nadie", [1], "08:00", "09:00", "r2") + hinted("fijo", [2], "08:00", "09:00", "r2"),
            )
        )
    )

    assert set(hint.groups) == {"g1"}
    assert hint.ignored == {"nadie": "el grupo no está en la entrada"}


def test_de_un_choque_entre_grupos_con_pista_solo_sale_uno():
    """g2 y g3 a la misma hora que g1 en r1 (HC-02): g1 choca con los dos y sale; g2 y g3 ya no chocan entre sí, así
    que entran. Con un solo choque entre dos (g1 y g2), sale el que la pista trae después."""
    data = parse_input(
        instance(groups=[group("g1", 2), group("g2", 2), group("g3", 2)], rooms=[room("r1"), room("r2"), room("r3")])
    )
    g2 = hinted("g2", [1], "07:00", "08:00", "r1") + hinted("g2", [2], "07:00", "08:00", "r2")
    g3 = hinted("g3", [1], "07:00", "08:00", "r3") + hinted("g3", [2], "07:00", "08:00", "r1")

    three = read_hint(ModelData.of(with_hint(data, VALID_G1 + g2 + g3)))
    two = read_hint(ModelData.of(with_hint(two_groups(), VALID_G1 + hinted("g2", [1, 2], "07:00", "08:00", "r1"))))

    assert set(three.groups) == {"g2", "g3"}
    assert list(three.ignored) == ["g1"]
    assert three.ignored["g1"].startswith("HC-02")
    assert set(two.groups) == {"g1"}
    assert two.ignored["g2"].startswith("HC-02")


def test_una_pista_invalida_no_rompe_la_corrida_y_el_log_lo_dice():
    """Con la pista de g2 fuera de la rejilla, la corrida programa a los dos sin duras, g1 donde decía su pista si no
    hay nada mejor, y el log dice qué pista ignoró y por qué."""
    data = with_hint(two_groups(), VALID_G1 + hinted("g2", [1, 2], "09:00", "11:00", "r2"))

    output = CpSatEngine().solve(data, RunContext(threads=1))

    assert output.status is RunStatus.FEASIBLE
    assert {entry.group_id for entry in output.sessions} == {"g1", "g2"}
    assert hard_violations(data, output.sessions) == []
    assert "Pista de la entrada: 1 grupo entró ya programado con ella." in output.log
    assert any(line.startswith("Ignoró la pista de 1 grupo, que se programó sin ella: g2 (") for line in output.log)


def test_con_la_pista_completa_no_hay_primera_vuelta():
    """Si la pista trae a todos, `lns` no arma ningún modelo: los grupos entran con sus sesiones y su opción."""
    data = with_hint(two_groups(), VALID_G1 + hinted("g2", [1, 2], "08:00", "09:00", "r2"))
    model_data = ModelData.of(data)
    hint = read_hint(model_data)

    search = lns(model_data, time_limit=2, limits=SearchLimits(), hint=hint)

    assert search.steps == []
    assert search.unplaced == []
    assert search.sessions == {group_id: entry.sessions for group_id, entry in hint.groups.items()}
    assert search.chosen == {group_id: entry.chosen for group_id, entry in hint.groups.items()}
    assert search.first_feasible_seconds is not None


def test_con_pista_la_primera_vuelta_solo_programa_lo_que_falta():
    """Con la pista de g1, la primera vuelta solo arma a g2 (con g1 fijo) y g1 se queda donde decía su pista."""
    data = with_hint(two_groups(), VALID_G1)
    model_data = ModelData.of(data)

    search = lns(model_data, time_limit=2, limits=SearchLimits(), hint=read_hint(model_data))

    assert search.steps[0].groups == 1
    assert search.unplaced == []
    assert [(s.day, s.starts_at, s.room_id) for s in search.sessions["g1"]] == [(1, "07:00", "r1"), (2, "07:00", "r1")]
    assert hard_violations(data, search.all_sessions) == []


def test_la_pista_de_cp_sat_lleva_la_opcion_y_el_salon_de_cada_dia():
    """`_add_hints` con los salones explícitos (sin conjunto): la opción de la búsqueda vale 1 y las demás 0, y en sus
    ventanas el salón que tiene ese día vale 1 y los demás 0; las ventanas que no usa, en 0."""
    model_data = ModelData.of(two_groups())
    model = cp_model.CpModel()
    variables = build_variables(model, model_data, DomainOptions(pool_rooms=False))
    sessions = hinted("g1", [1], "08:00", "09:00", "r2") + hinted("g1", [2], "08:00", "09:00", "r1")
    search = Search(sessions={"g1": sessions}, chosen={"g1": (((1, 1), (2, 1)), 1)})

    _add_hints(model, variables, search, model_data)

    hints = dict(zip(model.proto.solution_hint.vars, model.proto.solution_hint.values, strict=True))
    domain = variables.groups["g1"]
    chosen = [option for option in domain.options if hints[option.literal.index]]
    assert [(option.spread, option.block) for option in chosen] == [(((1, 1), (2, 1)), 1)]
    for window in domain.windows.values():
        rooms = {room_id for room_id, literal in window.rooms.items() if hints[literal.index]}
        if window in chosen[0].windows:
            assert rooms == {"r2" if window.day == 1 else "r1"}
        else:
            assert rooms == set()
    assert all(
        literal.index not in hints for w in variables.groups["g2"].windows.values() for literal in w.rooms.values()
    )


@pytest.mark.parametrize(
    ("name", "source"),
    [("tiny", "lns"), ("small", "cpsat-output.json"), ("medium", "cpsat-output.json"), ("medium", "lns")],
)
def test_con_la_pista_completa_el_resultado_nunca_es_peor_que_la_pista(name: str, source: str):
    """Una solución completa como pista: la salida guardada de CP-SAT o la de `lns` sin objetivo (que se puede
    mejorar). Con el plazo de la entrada, la corrida programa a todos, sin duras, y en orden lexicográfico (PS-10) no
    queda peor que la pista: la suma de los niveles del patrón no sube y, con la misma, el puntaje tampoco."""
    if source == "lns":
        data, _ = saved(name)
        sessions = lns(ModelData.of(data), time_limit=5, limits=SearchLimits()).all_sessions
    else:
        data, sessions = saved(name, source)
    data = with_hint(data, sessions)
    scorer = SoftScorer(data)

    output = CpSatEngine().solve(data, RunContext(threads=1))

    assert output.unscheduled == []
    assert hard_violations(data, output.sessions) == []
    before = (tier_sum(data, sessions), scorer.score(sessions).total)
    after = (tier_sum(data, output.sessions), output.soft_score.total)
    assert after <= before


def test_en_la_carga_real_la_pista_de_la_heuristica_entra_completa_y_nunca_queda_peor():
    """La salida de la heurística sobre la carga real (`heuristic-output.json`: 9 s, el 15 % de 60 s, como la corre
    `CpSatEngine` en PHP) como pista, con 6 s y 2 hilos: los 1 139 grupos entran ya programados, sin primera vuelta
    de `lns`, y la corrida nunca queda peor que la pista (PS-10), sin duras."""
    data, sessions = saved("department-multicampus", "heuristic-output.json")
    data = with_hint(data.model_copy(update={"time_limit_seconds": 6}), sessions)
    before = (tier_sum(data, sessions), SoftScorer(data).score(sessions).total)

    output = CpSatEngine().solve(data, RunContext(threads=2))

    assert "Pista de la entrada: 1139 grupos entraron ya programados con ella." in output.log
    assert output.unscheduled == []
    assert output.hard_violations == []
    assert (tier_sum(data, output.sessions), output.soft_score.total) <= before
