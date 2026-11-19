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
    result = lns(ModelData.of(data), time_limit=20, seed=7)

    assert result.unplaced == []
    assert result.first_feasible_seconds is not None
    assert hard_violations(data, result.all_sessions) == []
    assert set(result.sessions) == {group.id for group in data.groups if group.movable}


def test_en_el_fixture_infactible_quedan_los_que_no_caben():
    """`infeasible`: al menos dos de los tres grupos de t001 quedan sin programar, y lo programado no tiene duras."""
    data = fixture("infeasible")
    result = lns(ModelData.of(data), time_limit=10, seed=7)

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
    distintas cada día, o con un hueco entre sus horas de un día, no da pista. La pista solo orienta la búsqueda:
    quedarse en la hora actual es del objetivo (7.6)."""
    from app.model.search import _current_options
    from tests.instances import session

    data = model_data(
        instance(
            groups=[
                group("patron", 2, current_sessions=[session(1, "08:00", "09:00"), session(2, "08:00", "09:00")]),
                group("otro", 2, current_sessions=[session(1, "07:00", "08:00"), session(2, "09:00", "10:00")]),
                group(
                    "huecos",
                    4,
                    current_sessions=[
                        session(day, start, end)
                        for day in (1, 2)
                        for start, end in (("07:00", "08:00"), ("09:00", "10:00"))
                    ],
                ),
            ],
            rooms=[room("r1"), room("r2")],
        )
    )

    assert _current_options(data) == {"patron": (((1, 1), (2, 1)), 1)}


def test_si_no_alcanza_ni_a_armar_la_primera_vuelta_todos_quedan_sin_programar():
    """Sin tiempo, el armado se detiene (BuildTimeout) y la búsqueda lo dice: ningún grupo programado, todos fuera."""
    data = fixture("medium")
    result = lns(ModelData.of(data), time_limit=0, seed=7)

    assert result.sessions == {}
    assert result.unplaced == [group.id for group in data.groups if group.movable]


def test_un_vecindario_cuyos_salones_no_se_reparten_se_descarta_sin_perder_la_solucion(monkeypatch):
    """Si la fase B fallara en un vecindario (la fase A lo impide), ese vecindario se descarta y la búsqueda sigue con
    lo que tenía, en vez de perder todo lo programado."""
    from app.model import search as search_module
    from app.model.rooms import RoomAssignmentError

    original = search_module.sessions_of
    calls = []

    def first_only(*args):
        calls.append(1)
        if len(calls) > 1:
            raise RoomAssignmentError("Sin salón (prueba).")
        return original(*args)

    monkeypatch.setattr(search_module, "sessions_of", first_only)
    data = fixture("medium")
    result = lns(ModelData.of(data), time_limit=10, seed=7)

    assert len(calls) > 1
    assert result.sessions
    assert result.unplaced
    assert hard_violations(data, result.all_sessions) == []


def test_la_busqueda_revisa_la_cancelacion():
    """La cancelación del servicio (`RunContext.check_cancelled`) se revisa al armar y entre vecindarios: la excepción
    sale de la búsqueda."""
    from app.engine import RunContext
    from app.engine.base import RunCancelled
    from app.model.phases import SearchLimits

    context = RunContext(threads=1, is_cancelled=lambda: True)

    with pytest.raises(RunCancelled):
        lns(ModelData.of(fixture("medium")), time_limit=10, limits=SearchLimits.of(context))


def test_la_cancelacion_detiene_a_cp_sat_mientras_resuelve():
    """Un modelo que tardaría los 20 s de su límite (una mochila con muchas restricciones): cancelado a los 0.3 s,
    CP-SAT se detiene y la excepción sale en menos de 3 s."""
    import random
    import time

    from app.model.phases import SearchLimits, run_solver

    rng = random.Random(3)
    model = cp_model.CpModel()
    items = [model.new_bool_var("") for _ in range(300)]
    for _ in range(30):
        model.add(sum(rng.randint(1, 50) * item for item in items) <= 2000)
    model.maximize(sum(rng.randint(1, 50) * item for item in items))
    started = time.perf_counter()

    def cancel_after() -> None:
        if time.perf_counter() - started > 0.3:
            raise RuntimeError("cancelada")

    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = 20
    with pytest.raises(RuntimeError, match="cancelada"):
        run_solver(solver, model, SearchLimits(workers=1, check_cancelled=cancel_after))
    assert time.perf_counter() - started < 3


def test_los_limites_salen_del_contexto_de_la_corrida(monkeypatch):
    """Los hilos son los del servicio (`SOLVER_THREADS`) o los de la entrada; con 0, los núcleos del contenedor, nunca
    todos los de la máquina, y nunca más que los núcleos del contenedor (`threads=64` en uno de 2 CPU son 2). El tope
    de variables y la cancelación también vienen del contexto."""
    from app.engine import RunContext
    from app.model import phases

    monkeypatch.setattr(phases, "available_cpus", lambda: 2)
    context = RunContext(threads=0, max_model_variables=123)
    limits = phases.SearchLimits.of(context)

    assert limits.workers == 2
    assert limits.max_variables == 123
    assert limits.check_cancelled == context.check_cancelled
    assert phases.SearchLimits.of(RunContext(threads=1)).workers == 1
    assert phases.SearchLimits.of(RunContext(threads=64)).workers == 2
    assert limits.budget(1.0).max_variables == 123


def test_una_excepcion_del_aviso_de_cada_solucion_detiene_a_cp_sat_y_sale_de_run_solver():
    """El aviso de cada solución corre en un hilo de CP-SAT: si lanza (un aviso que revisa la cancelación, por ejemplo),
    la búsqueda se detiene y la excepción sale de `run_solver`, con o sin revisión de cancelación."""
    from app.model.phases import SearchLimits, run_solver

    model = cp_model.CpModel()
    values = [model.new_int_var(0, 10, f"x{i}") for i in range(5)]
    model.maximize(sum(values))

    def fail(callback) -> None:
        raise ValueError("aviso roto")

    for limits in (SearchLimits(), SearchLimits(check_cancelled=lambda: None)):
        with pytest.raises(ValueError, match="aviso roto"):
            run_solver(cp_model.CpSolver(), model, limits, on_solution=fail)


def test_un_modelo_invalido_no_se_esconde_como_infactible():
    """`MODEL_INVALID` es un defecto del motor: `run_solver` lanza `InvalidModel` con el mensaje de CP-SAT, y la
    corrida queda `failed` en vez de reportar «no cabe»."""
    from app.model.phases import InvalidModel, SearchLimits, run_solver

    model = cp_model.CpModel()
    model.new_int_var(5, 1, "vacía")

    with pytest.raises(InvalidModel, match="no es válido"):
        run_solver(cp_model.CpSolver(), model, SearchLimits())


def test_el_benchmark_reproduce_la_tabla_con_un_fixture():
    """`scripts/benchmark.py` corre cada forma en su proceso y da la fila de la tabla de solver.md."""
    import importlib.util
    from pathlib import Path

    path = Path(__file__).resolve().parents[1] / "scripts" / "benchmark.py"
    spec = importlib.util.spec_from_file_location("benchmark", path)
    benchmark = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(benchmark)

    output = benchmark.main(["tiny", "--forms", "lns,two-phase", "--time-limit", "5", "--threads", "1"])

    rows = output.splitlines()[2:]
    assert [row.split(" | ")[0] for row in rows] == ["| lns", "| two-phase"]
    assert all("3 de 3 | 0 |" in row for row in rows)


@pytest.mark.parametrize("name", ["small", "medium"])
def test_la_mejora_con_objetivo_baja_el_puntaje_sin_quitar_grupos_ni_agregar_duras(name):
    """7.6: sobre lo que programó `lns`, los vecindarios con objetivo solo se aceptan si bajan, en ese orden, los
    niveles del patrón y el puntaje de toda la solución (el de PHP; PS-10): nunca suben esa pareja, nunca quitan un
    grupo programado y no agregan duras. El avance que reporta nunca baja."""
    import time

    from app.model.phases import SearchLimits
    from app.model.score import SoftScorer
    from app.model.search import improve

    data = fixture(name)
    model_data = ModelData.of(data)
    limits = SearchLimits(workers=1)
    found = lns(model_data, time_limit=20, limits=limits, seed=7)
    placed = set(found.sessions)
    initial_tiers = sum(found.tiers.values())
    reports: list[tuple[tuple[int, int], float]] = []

    result = improve(
        model_data,
        found,
        time.perf_counter() + 5,
        limits=limits,
        seed=7,
        report=lambda state, progress: reports.append(((state.tiers, state.best.total), progress)),
    )

    assert SoftScorer(data).score(found.all_sessions) == result.best
    assert result.tiers == sum(found.tiers.values())
    assert (result.tiers, result.best.total) <= (initial_tiers, result.initial.total)
    assert set(found.sessions) == placed
    assert hard_violations(data, found.all_sessions) == []
    assert reports
    assert [key for key, _ in reports] == sorted((key for key, _ in reports), reverse=True)
    assert [progress for _, progress in reports] == sorted(progress for _, progress in reports)


def test_un_vecindario_cuyo_objetivo_pasa_del_tope_de_variables_se_descarta_sin_tumbar_la_mejora(monkeypatch):
    """El tope de variables (`SOLVER_MAX_MODEL_VARIABLES`) cuenta también el objetivo de cada vecindario: con un tope
    por encima del dominio pero por debajo del modelo con objetivo, cada vecindario se descarta (`too_large`), la
    solución se queda como estaba y la mejora termina sin error. Sin tope, la misma mejora sí acepta vecindarios."""
    import copy
    import time

    from app.model import search as search_module
    from app.model.phases import SearchLimits
    from app.model.search import improve

    model_data = ModelData.of(fixture("small"))
    found = lns(model_data, time_limit=20, limits=SearchLimits(workers=1), seed=7)
    sizes: list[tuple[int, int]] = []
    add_objective = search_module.add_objective

    def measured(model, *args, **kwargs):
        before = len(model.proto.variables)
        objective = add_objective(model, *args, **kwargs)
        sizes.append((before, len(model.proto.variables)))
        return objective

    monkeypatch.setattr(search_module, "add_objective", measured)
    free = improve(model_data, copy.deepcopy(found), time.perf_counter() + 5, limits=SearchLimits(workers=1), seed=7)
    assert free.accepted >= 1
    assert free.too_large == 0
    domain, full = max(before for before, _ in sizes), min(after for _, after in sizes)
    assert domain + 1 < full

    capped_search = copy.deepcopy(found)
    limits = SearchLimits(workers=1, max_variables=(domain + full) // 2)
    capped = improve(model_data, capped_search, time.perf_counter() + 5, limits=limits, seed=7)

    assert capped.accepted == 0
    assert capped.too_large == capped.steps >= 1
    assert capped.best == capped.initial
    assert capped_search.sessions == found.sessions


def test_un_vecindario_conserva_el_campus_de_la_ocupacion_de_fuera_sin_campus_code():
    """Sin `campus_code`, una ocupación de fuera es del campus del primer grupo del docente en la entrada (`score.py`).
    Si ese grupo no está programado, sale de la entrada del vecindario; aun así la ocupación conserva su campus, así que
    SC-09 en el vecindario cuenta lo mismo que el puntaje con el que se acepta."""
    from app.contract.output import ProposedSession
    from app.model.search import Search, _sub_input
    from tests.instances import slot, teacher

    data = parse_input(
        instance(
            campuses=[campus(), campus("C2")],
            groups=[group("primero", 1, teacher_id="t1"), group("otro", 1, teacher_id="t1", campus_code="C2")],
            teachers=[teacher("t1", busy=[slot(1, "09:00", "10:00")])],
            rooms=[room("r1"), room("r2", campus_code="C2")],
        )
    )
    placed = ProposedSession(
        group_id="otro", day=2, starts_at="07:00", ends_at="08:00", room_id="r2", kind="teoria", changed=True
    )

    sub = _sub_input(data, Search(sessions={"otro": [placed]}), {"otro"})

    assert [group.id for group in sub.groups] == ["otro"]
    assert [busy.campus_code for busy in sub.teachers[0].busy] == ["C1"]


def test_la_mejora_regresa_a_su_patron_a_un_grupo_que_ya_cabe_en_el_aunque_suba_el_puntaje():
    """PS-10 (confirmado el 18 de noviembre de 2026): el nivel del patrón va antes que las reglas. El caso del Riel A:
    g, de 2 horas en un campus de lunes a miércoles, está en lunes y miércoles (nivel 1) y su patrón, lunes y martes,
    está libre. Su docente prefiere lunes y miércoles a las 07:00 (SC-04, peso 4), así que dejarlo da 0 y regresarlo
    da 4. Aun así la mejora lo regresa a su patrón: la primera resolución de cada vecindario baja los niveles."""
    import time

    from app.contract.output import ProposedSession
    from app.model.phases import SearchLimits
    from app.model.search import Search, improve
    from tests.instances import slot, teacher

    data = parse_input(
        instance(
            campuses=[campus(days=[1, 2, 3], day_end="14:00")],
            groups=[group("g", 2, teacher_id="t1")],
            teachers=[teacher("t1", preferred=[slot(1, "07:00", "08:00"), slot(3, "07:00", "08:00")])],
            rooms=[room("r1"), room("r2")],
            soft_weights={"SC-04": 4},
        )
    )
    placed = [
        ProposedSession(
            group_id="g", day=day, starts_at="07:00", ends_at="08:00", room_id="r1", kind="teoria", changed=True
        )
        for day in (1, 3)
    ]
    search = Search(sessions={"g": placed}, chosen={"g": (((1, 1), (3, 1)), 0)}, tiers={"g": 1})

    result = improve(ModelData.of(data), search, time.perf_counter() + 5, limits=SearchLimits(workers=1), seed=7)

    assert search.tiers == {"g": 0}
    assert {session.day for session in search.sessions["g"]} == {1, 2}
    assert (result.tiers, result.initial.total, result.best.total) == (0, 0, 4)


def test_el_script_del_objetivo_da_el_puntaje_por_fase_y_la_salida_del_contrato(tmp_path):
    """`scripts/objective.py` (7.6) da una fila por fase con el puntaje de `score.py` y, con `--output`, la salida
    del contrato con ese mismo puntaje."""
    import importlib.util
    from pathlib import Path

    from app.contract import parse_output

    path = Path(__file__).resolve().parents[1] / "scripts" / "objective.py"
    spec = importlib.util.spec_from_file_location("objective_script", path)
    script = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(script)
    target = tmp_path / "salida.json"

    table = script.main(["small", "--time-limit", "5", "--threads", "1", "--output", str(target)])

    rows = table.splitlines()[2:]
    assert [row.split(" | ")[0] for row in rows] == ["| lns", "| improve", "| improve_rooms"]
    output = parse_output(json.loads(target.read_text()))
    assert output.unscheduled == []
    assert f"| {output.soft_score.total:,} |" in rows[-1]


def test_run_solver_avisa_cada_solucion_intermedia():
    """7.7: con `on_solution`, `run_solver` llama al aviso con cada solución que encuentra CP-SAT (el avance de la
    corrida), también con la revisión de la cancelación."""
    from app.model.phases import SearchLimits, run_solver

    model = cp_model.CpModel()
    items = [model.new_bool_var("") for _ in range(8)]
    model.add(sum(items) <= 5)
    model.maximize(sum((index + 1) * item for index, item in enumerate(items)))
    seen: list[float] = []

    solver = cp_model.CpSolver()
    limits = SearchLimits(workers=1, check_cancelled=lambda: None)
    status = run_solver(solver, model, limits, on_solution=lambda callback: seen.append(callback.objective_value))

    assert status == cp_model.OPTIMAL
    assert seen
    assert seen[-1] == solver.objective_value == 8 + 7 + 6 + 5 + 4


@pytest.mark.parametrize("name", ["medium", "infeasible"])
def test_el_aviso_de_lns_cuenta_los_programados_con_el_valor_del_objetivo(name):
    """El aviso de cada solución de la primera vuelta cuenta los programados con el valor del objetivo (sin recorrer
    las opciones en el hilo de CP-SAT): nunca más que los movibles, y el último es lo que quedó programado."""
    from app.model.phases import SearchLimits

    data = ModelData.of(fixture(name))
    reports: list[int] = []

    found = lns(data, time_limit=10, limits=SearchLimits(workers=1), seed=7, report=reports.append)

    assert reports
    assert all(0 <= placed <= len(data.movable) for placed in reports)
    assert reports[-1] == len(found.sessions)


def test_si_la_resolucion_de_nivel_no_termina_el_vecindario_sigue_con_las_reglas(monkeypatch):
    """🔵 del Riel A en el #647: si la resolución que baja los niveles no termina (UNKNOWN), el vecindario no se
    descarta: sigue con las reglas, con el nivel de cada grupo como tope. g está el lunes y el miércoles a las 09:00
    (nivel 1) y su docente prefiere esos días a las 07:00. Aunque la resolución de nivel no termine nunca, la de las
    reglas lo lleva a las 07:00 (SC-04 de 8 a 0) sin subir su nivel."""
    import time

    from app.contract.output import ProposedSession
    from app.model import search as search_module
    from app.model.phases import SearchLimits
    from app.model.search import Search, improve
    from tests.instances import slot, teacher

    solve_until = search_module.solve_until

    def level_never_ends(model, deadline, limits, seed, on_solution=None):
        solver, status = solve_until(model, deadline, limits, seed, on_solution)
        # La resolución de nivel minimiza los niveles: sus coeficientes son niveles (0 o 1 aquí), no minutos.
        if max(abs(c) for c in model.proto.objective.coeffs) <= 1:
            return solver, cp_model.UNKNOWN
        return solver, status

    monkeypatch.setattr(search_module, "solve_until", level_never_ends)
    data = parse_input(
        instance(
            campuses=[campus(days=[1, 2, 3], day_end="14:00")],
            groups=[group("g", 2, teacher_id="t1")],
            teachers=[teacher("t1", preferred=[slot(1, "07:00", "08:00"), slot(3, "07:00", "08:00")])],
            rooms=[room("r1"), room("r2")],
            soft_weights={"SC-04": 4},
        )
    )
    placed = [
        ProposedSession(
            group_id="g", day=day, starts_at="09:00", ends_at="10:00", room_id="r1", kind="teoria", changed=True
        )
        for day in (1, 3)
    ]
    search = Search(sessions={"g": placed}, chosen={"g": (((1, 1), (3, 1)), 2)}, tiers={"g": 1})

    result = improve(ModelData.of(data), search, time.perf_counter() + 3, limits=SearchLimits(workers=1), seed=7)

    assert (result.initial.total, result.best.total) == (8, 0)
    assert search.tiers == {"g": 1}
    assert {(session.day, session.starts_at) for session in search.sessions["g"]} == {(1, "07:00"), (3, "07:00")}
