"""El objetivo del modelo CP-SAT (`objective.py`, tarea 7.6): cada regla blanda como términos lineales, en las mismas
unidades que `SoftScorer` de PHP (y que `score.py`).

Cada prueba fija el horario a mano y compara el desglose del modelo con el calculado a mano y con `score.py`; luego
deja que el modelo elija y revisa lo que elige. El modelo es el exacto (sin conjunto de salones, todos los niveles),
con un hilo. SC-03 y SC-06 no están aquí: dependen del salón y van en la fase B (`test_model_room_model.py`).
"""

import random
from typing import Any

import pytest
from ortools.sat.python import cp_model

from app.contract import SOFT_RULES, parse_input
from app.model.constraints import add_hard_constraints
from app.model.data import HARD_RULES, ModelData
from app.model.objective import Objective, add_objective
from app.model.score import SoftScorer
from app.model.solution import sessions_of
from app.model.variables import BuildBudget, DomainOptions, ModelTooLarge, build_variables
from app.model.verify import hard_violations
from tests.instances import campus, group, instance, room, session, slot, teacher

DAY = [campus(day_end="14:00")]
TWO_CAMPUSES = [campus(day_end="14:00"), campus("C2", day_end="14:00")]
ROOMS = [room("r1"), room("r2")]

#: Las reglas del objetivo de la fase A.
SCHEDULE_RULES = ["SC-01", "SC-02", "SC-04", "SC-05", "SC-07", "SC-08", "SC-09"]


def weights(**values: int) -> dict[str, int]:
    return {rule: values.get(rule.replace("-", "_").lower(), 0) for rule in SOFT_RULES}


class Solved:
    """El modelo resuelto: el objetivo, su desglose y el puntaje de `score.py` sobre la salida. Por omisión, con todos
    los niveles del patrón; con `keep_tiers`, cada grupo hasta su nivel, como en la mejora (PS-10)."""

    def __init__(
        self,
        data: dict[str, Any],
        forced: dict[str, list[tuple[int, str]]],
        scope: set[str] | None,
        keep_tiers: dict[str, int] | None = None,
    ) -> None:
        source = parse_input(data)
        model_data = ModelData.of(source)
        model = cp_model.CpModel()
        options = DomainOptions(pool_rooms=False, all_tiers=True, keep_tiers=keep_tiers or {})
        variables = build_variables(model, model_data, options)
        add_hard_constraints(model, variables, model_data)
        self.objective: Objective = add_objective(model, variables, model_data, scope=scope)
        for group_id, units in forced.items():
            wanted = {(day, int(start[:2]) * 60 + int(start[3:])) for day, start in units}
            domain = variables.groups[group_id]
            options = [option for option in domain.options if _units(option, domain.grid.unit_minutes) == wanted]
            assert len(options) == 1, f"{group_id} no tiene una opción con esas sesiones"
            model.add(options[0].literal == 1)
        model.minimize(self.objective.expression())
        solver = cp_model.CpSolver()
        solver.parameters.max_time_in_seconds = 20
        solver.parameters.num_workers = 1
        assert solver.solve(model) == cp_model.OPTIMAL
        self.by_rule = self.objective.by_rule(solver)
        self.sessions = sessions_of(solver, variables, model_data)
        scope_list = None if scope is None else [group.id for group in source.groups if group.id in scope]
        self.score = SoftScorer(source).score(self.sessions, scope_list)
        assert hard_violations(source, self.sessions) == []

    def starts(self, group_id: str) -> set[tuple[int, str]]:
        return {(session.day, session.starts_at) for session in self.sessions if session.group_id == group_id}


def _units(option, unit: int) -> set[tuple[int, int]]:
    return {(window.day, window.start + i * unit) for window in option.windows for i in range(window.units)}


def solve(
    data: dict[str, Any],
    scope: set[str] | None = None,
    keep_tiers: dict[str, int] | None = None,
    **forced: list[tuple[int, str]],
) -> Solved:
    return Solved(data, forced, scope, keep_tiers)


def test_sc01_huecos_del_alumno_tipo_sin_contar_los_que_quedan_entre_dos_fijos():
    """SC-01, como en `test_model_score.py`: con g1 a las 09:00 dos horas de hueco; el modelo lo lleva a las 12:00,
    pegado al segundo fijo, donde el hueco entre los dos fijos ya no cuenta: 0."""
    data = instance(
        campuses=DAY,
        groups=[
            group("f1", 1, movable=False, cohorts=["plan:p1:1:C1"], fixed_sessions=[session(1, "07:00", "08:00")]),
            group("f2", 1, movable=False, cohorts=["plan:p1:1:C1"], fixed_sessions=[session(1, "11:00", "12:00")]),
            group("g1", 1, cohorts=["plan:p1:1:C1"]),
        ],
        rooms=ROOMS,
        soft_weights=weights(sc_01=10),
    )

    forced = solve(data, g1=[(1, "09:00")])
    assert forced.by_rule["SC-01"] == forced.score.by_rule["SC-01"] == 20
    best = solve(data)
    assert best.by_rule["SC-01"] == best.score.by_rule["SC-01"] == 0
    assert best.starts("g1") == {(1, "12:00")}


def test_sc01_en_un_vecindario_cuentan_los_huecos_de_los_fijos_del_alcance():
    """En un vecindario de la búsqueda los demás programados entran fijos pero siguen en el alcance: el hueco entre
    f1 y f2 cuenta (3 horas) y lo mejor es partirlo con g1 (2 horas), no irse a las 12:00."""
    data = instance(
        campuses=DAY,
        groups=[
            group("f1", 1, movable=False, cohorts=["plan:p1:1:C1"], fixed_sessions=[session(1, "07:00", "08:00")]),
            group("f2", 1, movable=False, cohorts=["plan:p1:1:C1"], fixed_sessions=[session(1, "11:00", "12:00")]),
            group("g1", 1, cohorts=["plan:p1:1:C1"]),
        ],
        rooms=ROOMS,
        soft_weights=weights(sc_01=10),
    )
    scope = {"f1", "f2", "g1"}

    outside = solve(data, scope, g1=[(1, "12:00")])
    assert outside.by_rule["SC-01"] == outside.score.by_rule["SC-01"] == 30
    best = solve(data, scope)
    assert best.by_rule["SC-01"] == best.score.by_rule["SC-01"] == 20


def test_sc02_horas_de_mas_en_un_dia():
    """SC-02: 2 horas en lunes y martes; el docente no puede el martes, así que van las dos el lunes: 1 hora de más."""
    data = instance(
        campuses=DAY,
        groups=[group("g1", 2, teacher_id="t1")],
        teachers=[teacher("t1", unavailable=[slot(2, "07:00", "14:00")])],
        rooms=ROOMS,
        soft_weights=weights(sc_02=6),
    )

    best = solve(data)
    assert best.by_rule["SC-02"] == best.score.by_rule["SC-02"] == 6
    free = solve({**data, "teachers": [teacher("t1")]})
    assert free.by_rule["SC-02"] == 0


def test_sc04_y_sc08_horas_fuera_de_los_preferidos():
    """SC-04 y SC-08 (docente de honorarios): prefiere el lunes de 07:30 a 09:00. Lunes y martes a las 07:00 dejan
    fuera media hora (1) y una hora (1): 2 en cada una. Lo mejor es el lunes a las 08:00 y el martes donde sea: 1."""
    data = instance(
        campuses=DAY,
        groups=[group("g1", 2, teacher_id="t1")],
        teachers=[teacher("t1", honorarios=True, preferred=[slot(1, "07:30", "09:00")])],
        rooms=ROOMS,
        soft_weights=weights(sc_04=4, sc_08=2),
    )

    forced = solve(data, g1=[(1, "07:00"), (2, "07:00")])
    assert (forced.by_rule["SC-04"], forced.by_rule["SC-08"]) == (8, 4)
    assert (forced.score.by_rule["SC-04"], forced.score.by_rule["SC-08"]) == (8, 4)
    best = solve(data)
    assert (best.by_rule["SC-04"], best.by_rule["SC-08"]) == (4, 2)


def test_sc05_huecos_del_docente_con_su_ocupacion_de_fuera():
    """SC-05: clase fuera de la corrida el lunes de 12:00 a 13:00. Lunes a las 07:00 deja 4 horas de hueco; el modelo
    lo pega a esa clase."""
    data = instance(
        campuses=TWO_CAMPUSES,
        groups=[group("g1", 2, teacher_id="t1")],
        teachers=[teacher("t1", busy=[slot(1, "12:00", "13:00", campus_code="C2")])],
        rooms=ROOMS,
        soft_weights=weights(sc_05=3),
    )

    forced = solve(data, g1=[(1, "07:00"), (2, "07:00")])
    assert forced.by_rule["SC-05"] == forced.score.by_rule["SC-05"] == 12
    best = solve(data)
    assert best.by_rule["SC-05"] == best.score.by_rule["SC-05"] == 0


def test_sc07_empalmes_de_semestres_seguidos():
    """SC-07: los grupos únicos de primero y segundo del mismo plan, los dos lunes y martes a las 07:00: 2 horas. El
    modelo los separa."""
    data = instance(
        campuses=DAY,
        groups=[group("g1", 2, cohorts=["plan:p1:1:C1"]), group("g2", 2, cohorts=["plan:p1:2:C1"])],
        rooms=ROOMS,
        soft_weights=weights(sc_07=3),
    )

    forced = solve(data, g1=[(1, "07:00"), (2, "07:00")], g2=[(1, "07:00"), (2, "07:00")])
    assert forced.by_rule["SC-07"] == forced.score.by_rule["SC-07"] == 6
    assert solve(data).by_rule["SC-07"] == 0


def test_sc09_traslado_justo_entre_campus():
    """SC-09: clase fuera de la corrida el lunes de 08:00 a 09:00 en C2; g1 en C1 a las 07:00 sale sin tiempo de
    traslado: 1. El modelo deja al menos una hora."""
    data = instance(
        campuses=TWO_CAMPUSES,
        groups=[group("g1", 1, teacher_id="t1")],
        teachers=[teacher("t1", busy=[slot(1, "08:00", "09:00", campus_code="C2")])],
        rooms=ROOMS,
        soft_weights=weights(sc_09=2),
    )

    forced = solve(data, g1=[(1, "07:00")])
    assert forced.by_rule["SC-09"] == forced.score.by_rule["SC-09"] == 2
    best = solve(data)
    assert best.by_rule["SC-09"] == 0
    assert best.starts("g1") not in ({(1, "07:00")}, {(1, "09:00")})


def test_sc09_con_hc01_apagada_dos_sesiones_que_terminan_a_la_vez_no_vuelven_infactible_el_modelo():
    """SC-09 sin HC-01: dos grupos del docente pueden terminar a la misma hora. g1 y g2, los dos el lunes a las 07:00
    en C1, y su clase de fuera a las 08:00 en C2: un traslado justo (el de g2 a la clase de fuera), 2 con peso 2, como
    en `score.py`. Si `sale` fuera la suma de las dos sesiones, `justo` tendría que valer 2 y no habría solución."""
    data = instance(
        campuses=TWO_CAMPUSES,
        groups=[group("g1", 1, teacher_id="t1"), group("g2", 1, teacher_id="t1")],
        teachers=[teacher("t1", busy=[slot(1, "08:00", "09:00", campus_code="C2")])],
        rooms=ROOMS,
        soft_weights=weights(sc_09=2),
        hard_rules=[rule for rule in HARD_RULES if rule != "HC-01"],
    )

    forced = solve(data, g1=[(1, "07:00")], g2=[(1, "07:00")])
    assert forced.by_rule["SC-09"] == forced.score.by_rule["SC-09"] == 2
    best = solve(data)
    assert best.by_rule["SC-09"] == best.score.by_rule["SC-09"] == 0


def test_lo_que_agrega_el_objetivo_cuenta_contra_el_tope_de_variables():
    """`SOLVER_MAX_MODEL_VARIABLES` también cuenta las variables del objetivo (huecos, empalmes y traslados), no solo
    las del dominio: con un tope por encima del dominio pero por debajo del modelo completo, armar el objetivo lanza
    `ModelTooLarge`; con lugar para todo, no."""
    data = random_instance(3)
    data["soft_weights"] = dict.fromkeys(SOFT_RULES, 5)
    model_data = ModelData.of(parse_input(data))

    def build(budget: BuildBudget) -> tuple[int, int]:
        model = cp_model.CpModel()
        variables = build_variables(model, model_data, DomainOptions(pool_rooms=False, all_tiers=True))
        domain = len(model.proto.variables)
        add_objective(model, variables, model_data, budget=budget)

        return domain, len(model.proto.variables)

    domain, full = build(BuildBudget())
    assert full > domain + 1
    assert build(BuildBudget(max_variables=full)) == (domain, full)
    with pytest.raises(ModelTooLarge, match="objetivo"):
        build(BuildBudget(max_variables=domain + 1))


@pytest.mark.parametrize("rule", SCHEDULE_RULES)
def test_un_peso_cero_no_crea_terminos(rule):
    """Una regla con peso cero no entra al objetivo. SC-03 y SC-06 nunca entran: son de la fase B."""
    data = random_instance(3)
    data["soft_weights"] = {**dict.fromkeys(SOFT_RULES, 5), rule: 0}
    source = parse_input(data)
    model_data = ModelData.of(source)
    model = cp_model.CpModel()
    variables = build_variables(model, model_data, DomainOptions(pool_rooms=False, all_tiers=True))
    objective = add_objective(model, variables, model_data)

    assert objective.rules[rule] == []
    assert objective.rules["SC-03"] == objective.rules["SC-06"] == []
    assert any(objective.rules[other] for other in SCHEDULE_RULES if other != rule)


def test_el_patron_va_antes_que_las_reglas_y_la_hora_actual_despues():
    """PS-10 (confirmado el 18 de noviembre de 2026), orden lexicográfico. Un grupo de 2 horas en un campus de lunes a
    miércoles, en su patrón (lunes y martes, nivel 0) y con su hora actual a las 09:00. Como en la mejora, su nivel es
    su tope (`keep_tiers`). Sin penalizaciones se queda a las 09:00: la hora actual desempata. Si el docente prefiere
    martes y miércoles a las 07:00, esos días darían SC-04 = 0, pero no son su patrón: se queda lunes y martes, y SC-04
    le gana a la hora actual: a las 07:00 (1 hora fuera, contra 2 a las 09:00). Sin el tope, el objetivo solo ve las
    reglas y lo saca de su patrón: por eso el nivel no es un término del objetivo, sino el tope."""
    grid = [campus(days=[1, 2, 3], day_end="14:00")]
    current = [session(1, "09:00", "10:00", "r1"), session(2, "09:00", "10:00", "r1")]
    data = instance(
        campuses=grid,
        groups=[group("g1", 2, teacher_id="t1", current_sessions=current)],
        teachers=[teacher("t1")],
        rooms=ROOMS,
        soft_weights=weights(sc_04=4),
    )
    in_pattern = {"g1": 0}

    assert solve(data, keep_tiers=in_pattern).starts("g1") == {(1, "09:00"), (2, "09:00")}
    preferring = {**data, "teachers": [teacher("t1", preferred=[slot(2, "07:00", "08:00"), slot(3, "07:00", "08:00")])]}
    best = solve(preferring, keep_tiers=in_pattern)
    assert best.starts("g1") == {(1, "07:00"), (2, "07:00")}
    assert best.by_rule["SC-04"] == best.score.by_rule["SC-04"] == 4
    assert solve(preferring).starts("g1") == {(2, "07:00"), (3, "07:00")}


def random_instance(seed: int) -> dict[str, Any]:
    """Una instancia chica al azar: dos campus de lunes a viernes, docentes con preferidos, honorarios y ocupación de
    fuera (sin empalmes entre lo fijo de un mismo docente), grupos fijos, cohortes de plan seguidas y un paquete, y
    pesos al azar."""
    rng = random.Random(seed)
    days = [1, 2, 3, 4, 5]
    taken: dict[str, set[tuple[int, int]]] = {}

    def free_hour(teacher_id: str | None) -> tuple[int, int]:
        while True:
            day, hour = rng.choice(days), rng.randint(7, 12)
            if teacher_id is None or (day, hour) not in taken.setdefault(teacher_id, set()):
                if teacher_id is not None:
                    taken[teacher_id].add((day, hour))
                return day, hour

    teachers = []
    for index in range(4):
        preferred = []
        for day in days:
            if rng.random() < 0.6:
                hour = rng.randint(7, 11)
                preferred.append(slot(day, f"{hour:02d}:00", f"{hour + rng.randint(1, 3):02d}:00"))
        busy = []
        for _ in range(rng.randint(0, 3)):
            day, hour = free_hour(f"t{index}")
            busy.append(slot(day, f"{hour:02d}:00", f"{hour + 1:02d}:00", campus_code=rng.choice(["C1", "C2"])))
        teachers.append(
            teacher(
                f"t{index}", honorarios=rng.random() < 0.3, preferred=preferred if rng.random() < 0.7 else [], busy=busy
            )
        )
    rooms = [
        room(f"r{index}", campus_code="C1" if index < 4 else "C2", building_id=rng.choice(["b1", "b2"]), capacity=45)
        for index in range(6)
    ]
    keys = ["plan:p1:1:C1", "plan:p1:2:C1", "plan:p1:3:C1", "package:k1:C1:1"]
    groups = []
    for index in range(rng.randint(4, 8)):
        campus_code = "C1" if rng.random() < 0.75 else "C2"
        teacher_id = rng.choice(["t0", "t1", "t2", "t3", None])
        extra: dict[str, Any] = {}
        hours = rng.randint(2, 5)
        if rng.random() < 0.2:
            used = [free_hour(teacher_id) for _ in range(2)]
            extra = {
                "movable": False,
                "fixed_sessions": [session(day, f"{hour:02d}:00", f"{hour + 1:02d}:00") for day, hour in used],
            }
            hours = 2
        groups.append(
            group(
                f"g{index}",
                hours,
                campus_code=campus_code,
                teacher_id=teacher_id,
                cohorts=[key for key in keys if rng.random() < 0.3] if campus_code == "C1" else [],
                **extra,
            )
        )

    return instance(
        campuses=[campus("C1", days=days, day_end="13:00"), campus("C2", days=days, day_end="13:00")],
        groups=groups,
        teachers=teachers,
        rooms=rooms,
        soft_weights={rule: rng.choice([0, 1, 2, 3, 10]) for rule in SOFT_RULES},
    )


@pytest.mark.parametrize("seed", range(20))
def test_el_desglose_del_modelo_es_el_de_php_en_instancias_al_azar(seed):
    """En el óptimo, el desglose del modelo por regla es el de `score.py` (el de PHP) sobre la salida: en rejillas de
    60 min y con lo fijo alineado, las cuentas del modelo son exactas."""
    data = random_instance(seed)
    try:
        solved = solve(data)
    except AssertionError as error:
        pytest.fail(f"la semilla {seed} no se resolvió: {error}")

    assert {rule: solved.by_rule[rule] for rule in SCHEDULE_RULES} == {
        rule: solved.score.by_rule[rule] for rule in SCHEDULE_RULES
    }
