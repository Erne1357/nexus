"""El motor CP-SAT del servicio (`app/engine/cpsat.py` y `app/model/solve.py`, tarea 7.7): el ciclo por la API, la
cancelación, el plazo con reloj real, el avance y lo que no cabe.

Las instancias rápidas son los fixtures compartidos; para el plazo y la cancelación, una carga generada de 150
grupos (`generated`), que no se resuelve en un instante.
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


def test_el_motor_se_registra_y_se_elige_con_solver_engine():
    assert isinstance(make_engine("cpsat"), CpSatEngine)
    assert Settings.from_env({"SOLVER_TOKEN": "t", "SOLVER_ENGINE": "cpsat"}).engine == "cpsat"
    assert Settings.from_env({"SOLVER_TOKEN": "t"}).engine == "trivial"


def test_ciclo_completo_por_la_api_con_el_motor_cpsat(tiny_input: dict[str, Any], auth: dict[str, str]):
    """`tiny` por `POST /solve` y `GET /runs/{id}` con `SOLVER_ENGINE=cpsat`: una salida válida, sin duras, con el
    puntaje de PHP (`score.py`) y el mejor puntaje de la corrida igual al de la salida."""
    with TestClient(create_app(make_settings(engine="cpsat"))) as client:
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


@pytest.mark.parametrize("name", ["tiny", "small", "medium"])
def test_el_motor_programa_los_fixtures_sin_duras(name: str):
    """Con el plazo de cada entrada: todo programado sin duras, `feasible` (nunca `optimal`: la búsqueda por
    vecindarios no prueba el óptimo) y el desglose de la salida suma el total."""
    data, expected = fixture(name)

    output = CpSatEngine().solve(data, context())

    assert output.status is RunStatus.FEASIBLE
    assert output.unscheduled == []
    assert hard_violations(data, output.sessions) == []
    assert set(expected["must_schedule"]) <= {session.group_id for session in output.sessions}
    assert output.soft_score.total == sum(output.soft_score.by_rule.model_dump().values())
    assert any("No se probó el óptimo" in line for line in output.log)


def test_el_fixture_infactible_reporta_sus_grupos_y_reglas_en_conflicto():
    """`infeasible`: t001 solo tiene libres 6 bloques para sus tres grupos de 4 horas. Quedan al menos dos sin
    programar, cada uno con HC-09 al frente (la indisponibilidad le quita casi todas sus opciones) y HC-01 (lo que le
    queda choca con el grupo de su docente que sí quedó); la corrida no se cae y los de t002 se programan."""
    data, expected = fixture("infeasible")

    output = CpSatEngine().solve(data, context())

    unscheduled = {entry.group_id: entry.reasons for entry in output.unscheduled}
    assert output.status is RunStatus.PARTIAL
    assert len(unscheduled) >= expected["min_unscheduled"]
    assert set(unscheduled) <= set(expected["may_leave_unscheduled"])
    for reasons in unscheduled.values():
        assert reasons[0].startswith("HC-09: el docente no está disponible")
        assert any(reason.startswith("HC-01: el docente ya tiene clase") for reason in reasons)
    assert set(expected["must_schedule"]) <= {session.group_id for session in output.sessions}
    assert hard_violations(data, output.sessions) == []


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
