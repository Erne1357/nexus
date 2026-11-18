"""El modelo duro sobre los fixtures compartidos con PHP (tarea 7.5).

Sin objetivo: solo factibilidad, con un límite corto. La solución se escribe al contrato, se valida con los modelos
pydantic y se revisa con el verificador de duras (`app/model/verify.py`), con lo que pide `expected.json`.
"""

import json
from collections import defaultdict
from itertools import pairwise
from typing import Any

import pytest
from ortools.sat.python import cp_model

from app.contract import SOFT_RULES, RunStatus, SolverInput, SolverOutput, parse_input, parse_output, to_dict
from app.contract.output import ProposedSession, RuleScores, RunMetrics, SoftScore
from app.model.constraints import add_hard_constraints
from app.model.data import ModelData
from app.model.pattern import minutes, tiers
from app.model.solution import sessions_of
from app.model.variables import build_variables
from app.model.verify import hard_violations
from tests.conftest import fixtures_dir


def fixture(name: str) -> tuple[SolverInput, dict[str, Any]]:
    folder = fixtures_dir() / "v1" / name

    return parse_input(json.loads((folder / "input.json").read_text())), json.loads(
        (folder / "expected.json").read_text()
    )


def solve_hard(data: SolverInput) -> tuple[int, list[ProposedSession]]:
    model_input = ModelData.of(data)
    model = cp_model.CpModel()
    variables = build_variables(model, model_input)
    add_hard_constraints(model, variables, model_input)
    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = 20
    solver.parameters.num_workers = 1
    solver.parameters.random_seed = 7
    status = solver.solve(model)
    if status not in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        return status, []

    return status, sessions_of(solver, variables, model_input)


def as_output(data: SolverInput, sessions: list[ProposedSession]) -> SolverOutput:
    return SolverOutput(
        version=data.version,
        run_id=data.run_id,
        status=RunStatus.FEASIBLE,
        sessions=sessions,
        unscheduled=[],
        hard_violations=[],
        soft_score=SoftScore(total=0, by_rule=RuleScores.model_validate(dict.fromkeys(SOFT_RULES, 0))),
        metrics=RunMetrics(elapsed_seconds=0, engine="cpsat", engine_version="test"),
    )


@pytest.mark.parametrize("name", ["tiny", "small", "medium"])
def test_el_modelo_duro_resuelve_el_fixture_sin_violaciones(name):
    data, expected = fixture(name)
    status, sessions = solve_hard(data)

    assert status in (cp_model.OPTIMAL, cp_model.FEASIBLE)
    output = parse_output(to_dict(as_output(data, sessions)))
    assert hard_violations(data, output.sessions) == []

    groups = {group.id: group for group in data.groups}
    rooms = {room.id: room for room in data.rooms}
    scheduled: dict[str, int] = defaultdict(int)
    for session in output.sessions:
        group = groups[session.group_id]
        assert group.movable
        assert session.room_id is not None
        assert rooms[session.room_id].campus_code == group.campus_code
        scheduled[session.group_id] += minutes(session.ends_at) - minutes(session.starts_at)
    for group_id in expected["must_schedule"]:
        assert scheduled[group_id] == groups[group_id].weekly_hours * 60


def test_el_modelo_duro_no_tiene_solucion_en_el_fixture_infactible():
    """`infeasible`: el docente t001 no tiene horas libres para sus tres grupos (HC-09); sin relajación (7.7) no hay
    solución que los programe a todos."""
    data, expected = fixture("infeasible")

    assert expected["feasible"] is False
    assert solve_hard(data)[0] == cp_model.INFEASIBLE


@pytest.mark.parametrize("name", ["tiny", "small", "medium"])
def test_cada_grupo_va_a_la_misma_hora_en_los_dias_de_su_patron(name):
    """PS-01 a PS-04: cada grupo empieza a la misma hora todos sus días, con a lo más 2 horas seguidas en un día, y su
    reparto de días es una opción de `SessionPattern` para sus horas en los días de su campus."""
    data, _ = fixture(name)
    _, sessions = solve_hard(data)
    grids = {campus.code: campus for campus in data.campuses}
    by_group: dict[str, list[ProposedSession]] = defaultdict(list)
    for session in sessions:
        by_group[session.group_id].append(session)

    for group in data.groups:
        if not group.movable:
            continue
        group_sessions = sorted(by_group[group.id], key=lambda session: (session.day, session.starts_at))
        by_day: dict[int, list[ProposedSession]] = defaultdict(list)
        for session in group_sessions:
            by_day[session.day].append(session)
        assert len({day_sessions[0].starts_at for day_sessions in by_day.values()}) == 1, group.id
        for day_sessions in by_day.values():
            assert len(day_sessions) <= 2
            assert all(a.ends_at == b.starts_at for a, b in pairwise(day_sessions))
        spread = tuple((day, len(day_sessions)) for day, day_sessions in sorted(by_day.items()))
        options = [option for tier in tiers(len(group_sessions), grids[group.campus_code].days) for option in tier]
        assert spread in options, group.id


def test_la_practica_de_small_va_en_su_laboratorio():
    """HC-06: las 2 horas de práctica de g003 van en r003, el único laboratorio."""
    data, _ = fixture("small")
    _, sessions = solve_hard(data)
    practice = [session for session in sessions if session.group_id == "g003" and session.kind == "practica"]

    assert len(practice) == 2
    assert {session.room_id for session in practice} == {"r003"}
