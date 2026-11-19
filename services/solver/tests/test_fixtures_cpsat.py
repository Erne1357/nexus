"""El motor CP-SAT sobre los fixtures compartidos con PHP (tarea 7.10; docs/arquitectura/solver.md, «Fixtures
compartidos y pruebas»).

Cada fixture de `tests/Fixtures/solver/v1` corre de punta a punta con el motor del servicio y su salida se revisa como
en `SolverFixturesTest.php`: el contrato, el `run_id`, el límite de tiempo, la rejilla y el salón del campus de cada
sesión y, con el verificador independiente (`verify.py`), cero duras. Además, lo que pide su `expected.json`: donde hay
solución, todo programado con sus horas (HC-12) y `feasible`; donde no, los grupos sin lugar en `unscheduled` con sus
reglas. `department-multicampus`, la carga real, lleva el marcador `realsize` y no corre en el CI de PR (ver
`pyproject.toml` y el README); se corre con `pytest -m realsize`.
"""

import json
import time
from collections import defaultdict
from typing import Any

import pytest
from ortools.sat.python import cp_model

from app.contract import RunStatus, SolverInput, parse_input, parse_output, to_dict
from app.contract.output import SolverOutput
from app.engine import CpSatEngine, RunContext
from app.model.data import ModelData
from app.model.pattern import minutes
from app.model.variables import DomainOptions, build_variables
from app.model.verify import hard_violations
from tests.conftest import fixtures_dir

#: El plazo de la carga real en la prueba: el del servicio por omisión (`SOLVER_DEFAULT_TIME_LIMIT`), no los 300 s de
#: una corrida de departamento que trae su entrada.
REALSIZE_SECONDS = 60


def deadline_margin(time_limit: int) -> float:
    """Lo que la corrida puede pasar de su plazo: la salida (el puntaje y el verificador) se arma después, y con la
    carga real eso es más de un segundo. `CpSatEngine` en PHP espera 30 s de más."""
    return max(1.5, time_limit * 0.05)


FIXTURES = [
    "tiny",
    "small",
    "medium",
    "infeasible",
    pytest.param("department-multicampus", marks=pytest.mark.realsize),
]


def fixture(name: str) -> tuple[SolverInput, dict[str, Any]]:
    folder = fixtures_dir() / "v1" / name
    data = parse_input(json.loads((folder / "input.json").read_text()))
    if name == "department-multicampus":
        data = data.model_copy(update={"time_limit_seconds": REALSIZE_SECONDS})

    return data, json.loads((folder / "expected.json").read_text())


def problems_of(data: SolverInput, output: SolverOutput) -> list[str]:
    """Lo que `SolverFixturesTest.php` le exige a todo motor: sesiones de grupos movibles de la entrada, en la rejilla
    de su campus, alineadas a sus bloques y, si el campus usa salones, en uno suyo."""
    groups = {group.id: group for group in data.groups}
    grids = {campus.code: campus for campus in data.campuses}
    room_campus = {room.id: room.campus_code for room in data.rooms}
    found = []
    for session in output.sessions:
        group = groups.get(session.group_id)
        if group is None or not group.movable:
            found.append(f"sesión de un grupo que no es movible en la entrada ({session.group_id})")
            continue
        grid = grids[group.campus_code]
        start, end = minutes(session.starts_at), minutes(session.ends_at)
        day_start, day_end = minutes(grid.day_start), minutes(grid.day_end)
        if session.day not in grid.days or start < day_start or end > day_end:
            found.append(f"{group.id}: fuera de la jornada de {grid.code}")
        if (start - day_start) % grid.block_minutes or (end - start) % grid.block_minutes:
            found.append(f"{group.id}: no alineada a bloques de {grid.block_minutes} min")
        if grid.uses_rooms and (session.room_id is None or room_campus.get(session.room_id) != group.campus_code):
            found.append(f"{group.id}: sin salón del campus {grid.code}")

    return found


@pytest.mark.parametrize("name", FIXTURES)
def test_cada_fixture_termina_sin_duras_dentro_del_limite(name: str):
    data, expected = fixture(name)
    started = time.perf_counter()

    output = CpSatEngine().solve(data, RunContext(threads=2))

    elapsed = time.perf_counter() - started
    payload = to_dict(output)
    assert to_dict(parse_output(payload)) == payload
    assert output.run_id == data.run_id
    assert elapsed <= data.time_limit_seconds + deadline_margin(data.time_limit_seconds), elapsed
    assert problems_of(data, output) == []
    assert hard_violations(data, output.sessions) == []
    assert output.hard_violations == []
    assert output.soft_score.total == sum(output.soft_score.by_rule.model_dump().values())
    assert any("No se probó el óptimo" in line for line in output.log)

    if expected["feasible"] is None:
        return
    groups = {group.id: group for group in data.groups}
    scheduled: dict[str, int] = defaultdict(int)
    for session in output.sessions:
        scheduled[session.group_id] += minutes(session.ends_at) - minutes(session.starts_at)
    for group_id in expected["must_schedule"]:
        assert scheduled[group_id] == groups[group_id].weekly_hours * 60, group_id
    unscheduled = {entry.group_id: entry.reasons for entry in output.unscheduled}
    if expected["feasible"]:
        assert output.status is RunStatus.FEASIBLE
        assert unscheduled == {}
    else:
        assert output.status in (RunStatus.PARTIAL, RunStatus.INFEASIBLE)
        assert len(unscheduled) >= expected["min_unscheduled"]
        assert set(unscheduled) <= set(expected["may_leave_unscheduled"])
        assert all(reasons and reasons[0].startswith("HC-") for reasons in unscheduled.values())


def test_el_fixture_infactible_reporta_sus_grupos_y_reglas_en_conflicto():
    """`infeasible`: t001 solo tiene libres 6 bloques para sus tres grupos de 4 horas. Quedan al menos dos sin
    programar, cada uno con HC-09 al frente (la indisponibilidad le quita casi todas sus opciones) y HC-01 (lo que le
    queda choca con el grupo de su docente que sí quedó); los de t002 se programan."""
    data, expected = fixture("infeasible")

    output = CpSatEngine().solve(data, RunContext(threads=1))

    unscheduled = {entry.group_id: entry.reasons for entry in output.unscheduled}
    assert output.status is RunStatus.PARTIAL
    assert len(unscheduled) >= expected["min_unscheduled"]
    for reasons in unscheduled.values():
        assert reasons[0].startswith("HC-09: el docente no está disponible")
        assert any(reason.startswith("HC-01: el docente ya tiene clase") for reason in reasons)


def test_small_respeta_el_prestamo_de_un_solo_grupo():
    """`small` trae un préstamo con `agreements[].group_id`: r004, de otro departamento, solo para g002 el martes de
    07:00 a 09:00 (HC-10, como `RoomLoan` por grupo). En el modelo exacto, r004 solo es salón de ventanas de g002
    dentro del préstamo; en la salida, ninguna otra sesión va en r004 y la de g002, si va, cae dentro."""
    data, _ = fixture("small")
    [loan] = [agreement for agreement in data.agreements if agreement.group_id is not None]
    exact = build_variables(cp_model.CpModel(), ModelData.of(data), DomainOptions(pool_rooms=False, all_tiers=True))
    windows = [
        window for domain in exact.groups.values() for window in domain.windows.values() if loan.room_id in window.rooms
    ]

    output = CpSatEngine().solve(data, RunContext(threads=1))

    assert windows
    assert {window.group_id for window in windows} == {loan.group_id}
    assert all(
        window.day == loan.day and minutes(loan.starts_at) <= window.start and window.end <= minutes(loan.ends_at)
        for window in windows
    )

    in_loaned_room = [session for session in output.sessions if session.room_id == loan.room_id]
    assert all(session.group_id == loan.group_id for session in in_loaned_room)
    assert all(
        session.day == loan.day and loan.starts_at <= session.starts_at and session.ends_at <= loan.ends_at
        for session in in_loaned_room
    )
    assert hard_violations(data, output.sessions) == []
