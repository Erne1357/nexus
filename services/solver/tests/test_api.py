"""Los endpoints del servicio (tarea 7.3): salud, autenticación, ciclo completo, límite de corridas y cancelación."""

from typing import Any

from fastapi.testclient import TestClient

from app import CONTRACT_VERSIONS, __version__
from app.api.factory import create_app
from app.contract import parse_output

from .conftest import BlockingEngine, make_settings, wait_until

Data = dict[str, Any]


def _run_state(client: TestClient, run_id: str, auth: dict[str, str]) -> str:
    return client.get(f"/runs/{run_id}", headers=auth).json()["status"]


def test_la_salud_responde_sin_token(client: TestClient):
    response = client.get("/health")

    assert response.status_code == 200
    assert response.json() == {
        "status": "ok",
        "version": __version__,
        "contract_versions": CONTRACT_VERSIONS,
        "runs_in_progress": 0,
    }
    assert CONTRACT_VERSIONS == [1]


def test_rechaza_las_peticiones_sin_token_o_con_token_malo(client: TestClient, tiny_input: Data):
    for headers in (
        {},
        {"Authorization": "Bearer otro"},
        {"Authorization": "test-token"},
        {"Authorization": "Basic test-token"},
    ):
        assert client.post("/solve", json=tiny_input, headers=headers).status_code == 401
        assert client.get("/runs/x", headers=headers).status_code == 401
        assert client.delete("/runs/x", headers=headers).status_code == 401

    assert client.post("/solve", content="no es json").status_code == 401


def test_una_entrada_invalida_devuelve_422_con_la_ruta_del_campo(
    client: TestClient, tiny_input: Data, auth: dict[str, str]
):
    tiny_input["groups"][1]["weekly_hours"] = 0

    response = client.post("/solve", json=tiny_input, headers=auth)

    assert response.status_code == 422
    assert {
        "path": "groups[1].weekly_hours",
        "message": "debe ser un entero de 1 en adelante",
    } in response.json()["detail"]


def test_un_cuerpo_que_no_es_json_devuelve_422(client: TestClient, auth: dict[str, str]):
    response = client.post("/solve", content="{roto", headers=auth)

    assert response.status_code == 422
    assert response.json()["detail"][0]["path"] == ""


def test_ciclo_completo_hasta_done_con_una_salida_valida(client: TestClient, tiny_input: Data, auth: dict[str, str]):
    submitted = client.post("/solve", json=tiny_input, headers=auth)

    assert submitted.status_code == 202
    run_id = submitted.json()["run_id"]
    assert submitted.json()["status"] == "queued"

    wait_until(lambda: _run_state(client, run_id, auth) == "done")
    body = client.get(f"/runs/{run_id}", headers=auth).json()

    assert body["run_id"] == run_id
    assert body["progress"] == 100
    assert body["best_score"] == 0
    output = parse_output(body["output"])
    assert output.run_id == tiny_input["run_id"]
    assert output.metrics.engine == "trivial"
    assert client.get("/health").json()["runs_in_progress"] == 0


def test_una_corrida_en_curso_no_trae_salida(tiny_input: Data, auth: dict[str, str], blocking_engine: BlockingEngine):
    with TestClient(create_app(make_settings(), blocking_engine)) as client:
        run_id = client.post("/solve", json=tiny_input, headers=auth).json()["run_id"]
        wait_until(lambda: _run_state(client, run_id, auth) == "running")

        body = client.get(f"/runs/{run_id}", headers=auth).json()
        assert "output" not in body
        assert client.get("/health").json()["runs_in_progress"] == 1

        blocking_engine.release.set()
        wait_until(lambda: _run_state(client, run_id, auth) == "done")


def test_responde_429_al_pasar_el_limite_de_corridas_simultaneas(
    tiny_input: Data, auth: dict[str, str], blocking_engine: BlockingEngine
):
    with TestClient(create_app(make_settings(max_concurrent_runs=1), blocking_engine)) as client:
        first = client.post("/solve", json=tiny_input, headers=auth)
        second = client.post("/solve", json=tiny_input, headers=auth)

        assert first.status_code == 202
        assert second.status_code == 429
        assert "límite 1" in second.json()["detail"]

        blocking_engine.release.set()
        wait_until(lambda: client.get("/health").json()["runs_in_progress"] == 0)

        assert client.post("/solve", json=tiny_input, headers=auth).status_code == 202


def test_delete_cancela_una_corrida_en_curso_y_libera_su_lugar(
    tiny_input: Data, auth: dict[str, str], blocking_engine: BlockingEngine
):
    with TestClient(create_app(make_settings(max_concurrent_runs=1), blocking_engine)) as client:
        run_id = client.post("/solve", json=tiny_input, headers=auth).json()["run_id"]
        wait_until(lambda: _run_state(client, run_id, auth) == "running")

        response = client.delete(f"/runs/{run_id}", headers=auth)

        assert response.status_code == 200
        assert response.json() == {"run_id": run_id, "status": "cancelled"}
        wait_until(lambda: client.get("/health").json()["runs_in_progress"] == 0)
        body = client.get(f"/runs/{run_id}", headers=auth).json()
        assert body["status"] == "cancelled"
        assert "output" not in body


def test_delete_de_una_corrida_terminada_conserva_su_estado(client: TestClient, tiny_input: Data, auth: dict[str, str]):
    run_id = client.post("/solve", json=tiny_input, headers=auth).json()["run_id"]
    wait_until(lambda: _run_state(client, run_id, auth) == "done")

    response = client.delete(f"/runs/{run_id}", headers=auth)

    assert response.json() == {"run_id": run_id, "status": "done"}
    assert "output" in client.get(f"/runs/{run_id}", headers=auth).json()


def test_una_corrida_desconocida_devuelve_404(client: TestClient, auth: dict[str, str]):
    assert client.get("/runs/no-existe", headers=auth).status_code == 404
    assert client.delete("/runs/no-existe", headers=auth).status_code == 404


def test_un_fallo_del_motor_deja_la_corrida_en_failed(tiny_input: Data, auth: dict[str, str]):
    class BrokenEngine:
        key = "broken"
        version = "test"

        def solve(self, data: Any, context: Any) -> Any:
            raise RuntimeError("falló el modelo")

    with TestClient(create_app(make_settings(), BrokenEngine())) as client:
        run_id = client.post("/solve", json=tiny_input, headers=auth).json()["run_id"]
        wait_until(lambda: _run_state(client, run_id, auth) == "failed")

        body = client.get(f"/runs/{run_id}", headers=auth).json()

        assert "falló el modelo" in body["error"]
        assert "output" not in body
        wait_until(lambda: client.get("/health").json()["runs_in_progress"] == 0)


def test_se_descartan_las_corridas_terminadas_mas_viejas(tiny_input: Data, auth: dict[str, str]):
    with TestClient(create_app(make_settings(engine="trivial", max_stored_runs=2))) as client:
        ids = []
        for _ in range(4):
            run_id = client.post("/solve", json=tiny_input, headers=auth).json()["run_id"]
            wait_until(lambda run_id=run_id: _run_state(client, run_id, auth) == "done")
            ids.append(run_id)

        assert client.get(f"/runs/{ids[0]}", headers=auth).status_code == 404
        assert client.get(f"/runs/{ids[-1]}", headers=auth).status_code == 200
