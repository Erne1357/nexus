"""El motor CP-SAT del servicio (`app/engine/cpsat.py` y `app/model/solve.py`, tarea 7.7): el ciclo por la API, la
cancelación, el plazo con reloj real, el avance y lo que no cabe.

Las instancias rápidas son los fixtures compartidos (cada uno de punta a punta, con su `expected.json`, en
`test_fixtures_cpsat.py`, 7.10); para el plazo y la cancelación, una carga generada de 150 grupos (`generated`), que no
se resuelve en un instante.
"""

import json
import random
import time
from collections import defaultdict
from typing import Any

import pytest
from fastapi.testclient import TestClient

from app.api.factory import create_app
from app.config import Settings
from app.contract import RunStatus, SolverInput, parse_input, parse_output, to_dict
from app.engine import CpSatEngine, RunCancelled, RunContext, make_engine
from app.model.score import SoftScorer
from app.model.verify import hard_violations
from tests.conftest import fixtures_dir, make_settings, wait_until
from tests.instances import campus, group, instance, room, slot, teacher


def fixture(name: str) -> tuple[SolverInput, dict[str, Any]]:
    folder = fixtures_dir() / "v1" / name

    return parse_input(json.loads((folder / "input.json").read_text())), json.loads(
        (folder / "expected.json").read_text()
    )


def generated(groups: int = 150, time_limit: int = 30, seed: int = 5) -> SolverInput:
    """Una carga de un departamento en un campus de lunes a viernes de 07:00 a 21:00: `groups` grupos de 4 o 5 horas,
    un docente por cada 5 con alguna hora no disponible, 40 salones (5 laboratorios) y cohortes de plan."""
    rng = random.Random(seed)
    teachers = [
        teacher(f"t{i:03d}", unavailable=[slot(rng.randint(1, 5), "07:00", f"{rng.randint(8, 12):02d}:00")])
        for i in range(groups // 5)
    ]
    rooms = [
        room(f"r{i:03d}", type="laboratorio" if i < 5 else "aula", capacity=rng.choice([30, 40])) for i in range(40)
    ]
    created = []
    for i in range(groups):
        lab = i % 10 == 0
        created.append(
            group(
                f"g{i:03d}",
                rng.choice([4, 5]),
                teacher_id=f"t{i // 5:03d}",
                cohorts=[f"plan:p1:{i % 9 + 1}:C1"] if i % 5 == 0 else [],
                needs_lab=lab,
                practice_hours=2 if lab else 0,
                capacity=30,
            )
        )

    return parse_input(
        instance(
            campuses=[campus(days=[1, 2, 3, 4, 5], day_end="21:00")],
            groups=created,
            teachers=teachers,
            rooms=rooms,
            time_limit_seconds=time_limit,
            soft_weights={"SC-01": 10, "SC-02": 6, "SC-04": 4, "SC-05": 3, "SC-06": 2, "SC-07": 3},
        )
    )


def context(**overrides: Any) -> RunContext:
    return RunContext(threads=overrides.pop("threads", 1), **overrides)


def test_cp_sat_es_el_motor_por_omision_y_el_de_prueba_solo_si_se_pide():
    """Desde la 7.10 (acuerdo con el Riel C), el servicio usa CP-SAT si `SOLVER_ENGINE` no dice otra cosa; el de prueba
    queda para pruebas y CI con `SOLVER_ENGINE=trivial`."""
    assert isinstance(make_engine("cpsat"), CpSatEngine)
    assert Settings.from_env({"SOLVER_TOKEN": "t"}).engine == "cpsat"
    assert Settings.from_env({"SOLVER_TOKEN": "t", "SOLVER_ENGINE": " "}).engine == "cpsat"
    assert Settings.from_env({"SOLVER_TOKEN": "t", "SOLVER_ENGINE": "trivial"}).engine == "trivial"


def test_ciclo_completo_por_la_api_con_el_motor_cpsat(tiny_input: dict[str, Any], auth: dict[str, str]):
    """`tiny` por `POST /solve` y `GET /runs/{id}` con el motor por omisión: una salida válida de CP-SAT, sin duras,
    con el puntaje de PHP (`score.py`) y el mejor puntaje de la corrida igual al de la salida."""
    with TestClient(create_app(make_settings())) as client:
        run_id = client.post("/solve", json=tiny_input, headers=auth).json()["run_id"]
        wait_until(lambda: client.get(f"/runs/{run_id}", headers=auth).json()["status"] == "done", timeout=30)
        body = client.get(f"/runs/{run_id}", headers=auth).json()

    data = parse_input(tiny_input)
    output = parse_output(body["output"])
    assert output.metrics.engine == "cpsat"
    assert output.status is RunStatus.FEASIBLE
    assert hard_violations(data, output.sessions) == []
    assert output.soft_score == SoftScorer(data).score(output.sessions).to_contract()
    assert body["progress"] == 100
    assert body["best_score"] == output.soft_score.total


def test_un_grupo_sin_ninguna_opcion_queda_fuera_con_su_regla_y_los_demas_se_programan():
    """Un grupo de 50 alumnos sin salón que le quepa (HC-05): no vuelve infactible la corrida."""
    data = parse_input(
        instance(
            groups=[group("cabe", 2), group("grande", 2, capacity=50)],
            rooms=[room("r1"), room("r2")],
        )
    )

    output = CpSatEngine().solve(data, context())

    assert [entry.group_id for entry in output.unscheduled] == ["grande"]
    assert output.unscheduled[0].reasons[0].startswith("HC-05:")
    assert {session.group_id for session in output.sessions} == {"cabe"}
    assert output.status is RunStatus.PARTIAL


def test_el_avance_sube_hasta_99_y_el_ultimo_mejor_puntaje_es_el_de_la_salida():
    reports: list[tuple[int, int | None]] = []

    output = CpSatEngine().solve(
        generated(time_limit=8), context(report=lambda percent, best: reports.append((percent, best)))
    )

    percents = [percent for percent, _ in reports]
    assert percents == sorted(percents)
    # Al programar (con cada solución de la primera vuelta y cada vecindario), hasta 40; al mejorar, de 40 a 95.
    assert percents[0] <= 40
    assert any(40 < percent < 95 for percent in percents)
    assert percents[-1] == 99
    assert reports[-1][1] == output.soft_score.total
    # Antes de tener a todos programados no hay puntaje que reportar; después, nunca falta.
    assert all(best is not None for percent, best in reports if percent > 40)


def test_respeta_el_plazo_con_reloj_real():
    """150 grupos con 4 s: la corrida termina cerca del plazo (no antes de usar casi todo lo que tiene para mejorar,
    ni mucho después), con todo programado y sin duras."""
    data = generated(time_limit=4)
    started = time.perf_counter()

    output = CpSatEngine().solve(data, context(threads=2))

    elapsed = time.perf_counter() - started
    assert 2.0 < elapsed < 4 + 1.5
    assert output.metrics.elapsed_seconds <= elapsed + 0.01
    assert hard_violations(data, output.sessions) == []
    assert output.unscheduled == []


def test_la_cancelacion_detiene_la_corrida():
    """Se cancela con el primer aviso de avance: la excepción sale del motor en menos de 3 s, aunque el plazo era de
    30."""
    cancelled = False

    def report(percent: int, best: int | None) -> None:
        nonlocal cancelled
        cancelled = True

    started = time.perf_counter()
    with pytest.raises(RunCancelled):
        CpSatEngine().solve(generated(), context(report=report, is_cancelled=lambda: cancelled))

    assert time.perf_counter() - started < 3


def test_cancelar_por_la_api_libera_el_lugar(auth: dict[str, str]):
    payload = to_dict(generated())
    with TestClient(create_app(make_settings(engine="cpsat", max_concurrent_runs=1, threads=1))) as client:
        run_id = client.post("/solve", json=payload, headers=auth).json()["run_id"]
        wait_until(lambda: client.get(f"/runs/{run_id}", headers=auth).json()["status"] == "running")

        assert client.delete(f"/runs/{run_id}", headers=auth).json()["status"] == "cancelled"
        wait_until(lambda: client.get("/health").json()["runs_in_progress"] == 0, timeout=5)
        assert "output" not in client.get(f"/runs/{run_id}", headers=auth).json()


def test_los_motivos_salen_de_las_reglas_que_quitaron_opciones():
    """El docente solo puede el lunes de 07:00 a 08:00 y tiene dos grupos de una hora: uno queda fuera. De sus 6
    opciones (lunes o martes, a las 07:00, 08:00 o 09:00), HC-09 le quita 5 y va al frente; HC-01, la que ocupa el otro
    grupo."""
    data = parse_input(
        instance(
            groups=[group("g1", 1, teacher_id="t1"), group("g2", 1, teacher_id="t1")],
            teachers=[teacher("t1", unavailable=[slot(1, "08:00", "10:00"), slot(2, "07:00", "10:00")])],
            rooms=[room("r1"), room("r2")],
        )
    )

    output = CpSatEngine().solve(data, context())

    reasons = defaultdict(list, {entry.group_id: entry.reasons for entry in output.unscheduled})
    left = [group_id for group_id in ("g1", "g2") if reasons[group_id]]
    assert len(left) == 1
    assert reasons[left[0]] == [
        "HC-09: el docente no está disponible en 5 de sus 6 opciones.",
        "HC-01: el docente ya tiene clase en 1 de sus 6 opciones, con 1 grupo programado.",
    ]


def test_hc08_sale_una_sola_vez_por_grupo():
    """HC-08 no depende del horario (PS-08): `verify.py` ya la trae de la entrada, así que cada grupo movible del
    docente lleva una sola violación. g1 y g2 de 2 horas con un docente al que le quedan 3: dos HC-08, no cuatro."""
    data = parse_input(
        instance(
            groups=[group("g1", 2, teacher_id="t1"), group("g2", 2, teacher_id="t1")],
            teachers=[teacher("t1", remaining_hours=3)],
            rooms=[room("r1"), room("r2")],
        )
    )

    output = CpSatEngine().solve(data, context())

    hours = [violation for violation in output.hard_violations if violation.rule == "HC-08"]
    assert sorted(group_id for violation in hours for group_id in violation.group_ids) == ["g1", "g2"]
    assert output.status is RunStatus.PARTIAL


def test_con_muchos_grupos_sin_lugar_todos_llevan_su_regla_y_no_se_arma_uno_por_uno(monkeypatch):
    """40 grupos de 50 alumnos sin salón que les quepa (HC-05) y uno que sí cabe. Un solo modelo con todos los que no
    quedaron da las reglas de cada uno: a ninguno de los 40 se le arma su propia entrada (`_alone`), y todos salen con
    HC-05 al frente, sin «no se alcanzó». Uno con lugar se programa."""
    from app.model import solve as solve_module

    alone = solve_module._alone
    tried: list[str] = []

    def counted(data, search, group_id, *args):
        tried.append(group_id)
        return alone(data, search, group_id, *args)

    monkeypatch.setattr(solve_module, "_alone", counted)
    big = [group(f"grande{i:02d}", 2, capacity=50) for i in range(40)]
    data = parse_input(instance(groups=[group("cabe", 2), *big], rooms=[room("r1"), room("r2")]))

    output = CpSatEngine().solve(data, context())

    reasons = {entry.group_id: entry.reasons for entry in output.unscheduled}
    assert set(reasons) == {entry["id"] for entry in big}
    assert all(entry[0].startswith("HC-05:") for entry in reasons.values())
    assert not set(tried) & set(reasons)
    assert {session.group_id for session in output.sessions} == {"cabe"}
    assert output.status is RunStatus.PARTIAL


def test_infactible_solo_si_a_todos_los_que_faltan_se_lo_impiden_sus_reglas():
    """`infeasible` es «no cabe»: ningún grupo quedó y a todos los que faltan se lo impiden sus reglas. Si a alguno le
    faltó tiempo, la corrida es `partial`, aunque no haya quedado ninguno."""
    from app.model.solve import Unplaced, run_status

    rules, time_out = Unplaced(["HC-05: …"], by_rules=True), Unplaced(["No se alcanzó…"], by_rules=False)

    assert run_status(True, False, [rules, rules], False) is RunStatus.INFEASIBLE
    assert run_status(True, False, [rules, time_out], False) is RunStatus.PARTIAL
    assert run_status(True, True, [rules], False) is RunStatus.PARTIAL
    assert run_status(True, True, [], True) is RunStatus.PARTIAL
    assert run_status(True, True, [], False) is RunStatus.FEASIBLE

    only_big = parse_input(instance(groups=[group("grande", 2, capacity=50)], rooms=[room("r1")]))
    output = CpSatEngine().solve(only_big, context())
    assert output.status is RunStatus.INFEASIBLE
    assert output.unscheduled[0].reasons[0].startswith("HC-05:")


def test_sin_tiempo_en_la_reserva_el_grupo_con_opciones_dice_que_falto_tiempo_y_sus_reglas_siguen():
    """Si la reserva se acabó, un grupo que sí tiene opciones con lo demás fijo sale con «no se alcanzó» (y la corrida
    no sería `infeasible`), mientras que uno sin opciones sale con su regla: los dos llevan motivo. Si ni siquiera
    alcanzó el plazo de la corrida para armar el modelo de los motivos, todos dicen que faltó tiempo."""
    from app.model.data import ModelData
    from app.model.phases import SearchLimits
    from app.model.search import Search
    from app.model.solve import _complete

    data = ModelData.of(
        parse_input(instance(groups=[group("cabe", 2), group("grande", 2, capacity=50)], rooms=[room("r1")]))
    )
    search = Search(unplaced=["cabe", "grande"])

    found = _complete(data, search, time.perf_counter() - 1, time.perf_counter() + 10, SearchLimits(), seed=0)

    assert found["cabe"].reasons[0].startswith("No se alcanzó a programar en el plazo de la corrida")
    assert not found["cabe"].by_rules
    assert found["grande"].reasons[0].startswith("HC-05:")
    assert found["grande"].by_rules
    late = _complete(data, search, time.perf_counter() - 1, time.perf_counter() - 1, SearchLimits(), seed=0)
    assert all(not entry.by_rules and "plazo" in entry.reasons[0] for entry in late.values())


def test_sin_todos_programados_no_hay_mejor_puntaje_ni_soluciones_completas():
    """El mejor puntaje del avance es de una solución con todos los grupos: con uno que no cabe, nunca se reporta (la
    corrida pone el de la salida al terminar), e `intermediate_solutions` cuenta soluciones completas: 0. Con todos,
    al menos la primera."""
    reports: list[int | None] = []
    data = parse_input(
        instance(groups=[group("cabe", 2), group("grande", 2, capacity=50)], rooms=[room("r1"), room("r2")])
    )

    partial = CpSatEngine().solve(data, context(report=lambda percent, best: reports.append(best)))

    assert reports
    assert all(best is None for best in reports)
    assert partial.metrics.intermediate_solutions == 0
    complete = CpSatEngine().solve(fixture("tiny")[0], context())
    assert complete.metrics.intermediate_solutions >= 1


def test_la_reserva_programa_juntos_a_muchos_grupos_que_caben_sin_armar_uno_por_uno(monkeypatch):
    """Revisión del Riel A: armar la entrada de un grupo cuesta de 0.2 a 0.3 s en la carga real, así que con 40 grupos
    que caben y la reserva repartida uno por uno no se programaba ninguno. Aquí cada intento por grupo (`_alone`)
    cuesta 0.1 s de más: en una reserva de 1 s, el modelo de la reserva los programa juntos a los 40, sin duras, y
    `_alone` no se llama."""
    from app.model import solve as solve_module
    from app.model.data import ModelData
    from app.model.phases import SearchLimits
    from app.model.search import Search

    alone = solve_module._alone
    tried: list[str] = []

    def slow(data, search, group_id, *args):
        tried.append(group_id)
        time.sleep(0.1)
        return alone(data, search, group_id, *args)

    monkeypatch.setattr(solve_module, "_alone", slow)
    groups = [group(f"g{i:02d}", 1, teacher_id=f"t{i:02d}") for i in range(40)]
    source = parse_input(
        instance(
            campuses=[campus(days=[1, 2, 3, 4, 5], day_end="14:00")],
            groups=groups,
            teachers=[teacher(f"t{i:02d}") for i in range(40)],
            rooms=[room(f"r{i}") for i in range(8)],
        )
    )
    search = Search(unplaced=[entry["id"] for entry in groups])
    started = time.perf_counter()

    found = solve_module._complete(ModelData.of(source), search, started + 1, started + 5, SearchLimits(workers=1), 0)

    assert found == {}
    assert len(search.sessions) == 40
    assert tried == []
    assert hard_violations(source, search.all_sessions) == []
    assert time.perf_counter() - started < 1.5


def test_si_cp_sat_prueba_que_no_caben_mas_los_que_quedan_no_caben_con_los_programados():
    """Dos grupos de una hora sin lugar y su docente solo puede el lunes a las 07:00: el modelo de la reserva programa
    uno y prueba que no cabe otro, así que el segundo sale con «no cabe con los grupos ya programados» y sus reglas
    (HC-09), sin intentarlo solo."""
    from app.model.data import ModelData
    from app.model.phases import SearchLimits
    from app.model.search import Search
    from app.model.solve import _complete

    source = parse_input(
        instance(
            groups=[group("g1", 1, teacher_id="t1"), group("g2", 1, teacher_id="t1")],
            teachers=[teacher("t1", unavailable=[slot(1, "08:00", "10:00"), slot(2, "07:00", "10:00")])],
            rooms=[room("r1"), room("r2")],
        )
    )
    search = Search(unplaced=["g1", "g2"])

    found = _complete(ModelData.of(source), search, time.perf_counter() + 2, time.perf_counter() + 5, SearchLimits(), 0)

    assert len(search.sessions) == 1
    [(left, entry)] = found.items()
    assert left not in search.sessions
    assert entry.by_rules
    assert (
        entry.reasons[0] == "No cabe con los grupos ya programados: ninguna de sus 1 opciones libres sirve con ellos."
    )
    assert entry.reasons[1].startswith("HC-09: el docente no está disponible en 5 de sus 6 opciones")


@pytest.mark.parametrize("removed", [10, 40])
def test_en_la_carga_real_la_reserva_vuelve_a_programar_los_grupos_que_se_quitan(removed: int):
    """Revisión del Riel A, sobre la carga real: de la salida guardada de `department-multicampus` (los 1 139 grupos
    programados, sin duras) se quitan `removed` grupos. Sus lugares quedan libres, así que todos caben. Con una reserva
    de 3 s (la de una corrida de 60 s) y 2 hilos, la reserva los vuelve a programar a todos, sin duras. Con el modelo
    de todos los niveles programaba 5 de 10 y 0 de 40."""
    from app.model.data import ModelData
    from app.model.phases import SearchLimits
    from app.model.search import Search
    from app.model.solve import _complete

    folder = fixtures_dir() / "v1" / "department-multicampus"
    source = parse_input(json.loads((folder / "input.json").read_text()))
    saved = parse_output(json.loads((folder / "cpsat-output.json").read_text()))
    by_group: dict[str, list] = defaultdict(list)
    for session in saved.sessions:
        by_group[session.group_id].append(session)
    taken = sorted(by_group)[:: len(by_group) // removed][:removed]
    search = Search(
        sessions={group_id: sessions for group_id, sessions in by_group.items() if group_id not in taken},
        unplaced=taken,
    )
    started = time.perf_counter()

    found = _complete(ModelData.of(source), search, started + 3, started + 10, SearchLimits(workers=2), 0)

    assert found == {}
    assert set(taken) <= set(search.sessions)
    assert hard_violations(source, search.all_sessions) == []


def test_en_la_carga_real_los_que_necesitan_otros_dias_entran_en_la_segunda_ronda_sin_intentarlos_uno_por_uno(
    monkeypatch,
):
    """Segunda pasada del Riel A: de la salida guardada de `department-multicampus` se quitan 20 grupos que están fuera
    de su patrón (otros días o un día de 2 horas) y 20 que no. La primera ronda de la reserva, con los niveles por
    necesidad, deja fuera a algunos de los primeros (de 2 a 4 al medirlo); la segunda, con todos sus niveles y solo
    para ellos, los programa, así que ninguno cae en `_alone`. Sin la segunda ronda, `_alone` los intentaba uno por uno
    y, con la máquina cargada, a algunos no les alcanzaba la reserva de 3 s."""
    from app.contract.input import HintSession
    from app.model import solve as solve_module
    from app.model.data import ModelData
    from app.model.hint import read_hint
    from app.model.phases import SearchLimits
    from app.model.search import Search

    alone = solve_module._alone
    tried: list[str] = []

    def counted(data, search, group_id, *args):
        tried.append(group_id)
        return alone(data, search, group_id, *args)

    monkeypatch.setattr(solve_module, "_alone", counted)
    folder = fixtures_dir() / "v1" / "department-multicampus"
    source = parse_input(json.loads((folder / "input.json").read_text()))
    saved = parse_output(json.loads((folder / "cpsat-output.json").read_text()))
    hints = [HintSession(**session.model_dump(exclude={"changed"})) for session in saved.sessions]
    tiers = {
        group_id: entry.tier
        for group_id, entry in read_hint(
            ModelData.of(source.model_copy(update={"hint_sessions": hints}))
        ).groups.items()
    }
    off_pattern = sorted(group_id for group_id, tier in tiers.items() if tier)
    in_pattern = sorted(group_id for group_id, tier in tiers.items() if not tier)
    taken = off_pattern[:20] + in_pattern[:: len(in_pattern) // 20][:20]
    by_group: dict[str, list] = defaultdict(list)
    for session in saved.sessions:
        by_group[session.group_id].append(session)
    search = Search(
        sessions={group_id: sessions for group_id, sessions in by_group.items() if group_id not in taken},
        unplaced=taken,
    )
    started = time.perf_counter()

    found = solve_module._complete(ModelData.of(source), search, started + 3, started + 10, SearchLimits(workers=2), 0)

    assert found == {}
    assert set(taken) <= set(search.sessions)
    assert tried == []
    assert hard_violations(source, search.all_sessions) == []
