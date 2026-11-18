"""Los salones en dos fases (`rooms.py`): la fase A cuenta salones por bloque y la fase B los reparte.

La fase B nunca queda sin salón si la A tiene solución, y la A tiene solución si y solo si el modelo exacto (un `z`
por ventana y salón) la tiene: se prueba con instancias generadas con semilla fija, con capacidades, laboratorios,
bloqueos, ocupación de fuera, préstamos, grupos de otro departamento, candidatos y días de 2 horas.
"""

import random
from typing import Any

import pytest
from ortools.sat.python import cp_model

from app.contract import parse_input
from app.model import constraints
from app.model.data import ModelData
from app.model.phases import FEASIBLE, solve_hard
from app.model.rooms import NONE, PooledSession, RoomAssignmentError, RoomPool, match
from app.model.search import lns
from app.model.verify import hard_violations
from tests.instances import campus, group, instance, model_data, room, slot, teacher


def random_instance(seed: int) -> dict[str, Any]:
    rng = random.Random(seed)
    rooms = []
    for index in range(rng.randint(2, 5)):
        lab = rng.random() < 0.35
        extra: dict[str, Any] = {}
        if rng.random() < 0.2:
            extra["owner_department_id"] = "d999"
        if rng.random() < 0.3:
            hour = rng.randint(7, 9)
            extra["blocks" if rng.random() < 0.5 else "busy"] = [
                slot(rng.randint(1, 3), f"{hour:02d}:00", f"{hour + 1:02d}:00")
            ]
        rooms.append(
            room(f"r{index}", type="laboratorio" if lab else "aula", capacity=rng.choice([25, 35, 45, 45]), **extra)
        )
    agreements = [
        {
            "room_id": item["id"],
            "to_department_id": "d001",
            "day": rng.randint(1, 3),
            "starts_at": "07:00",
            "ends_at": "09:00",
        }
        for item in rooms
        if item.get("owner_department_id") and rng.random() < 0.7
    ]
    teachers = [teacher(f"t{index}") for index in range(3)]
    groups = []
    for index in range(rng.randint(3, 8)):
        hours = rng.randint(1, 4)
        lab = rng.random() < 0.25
        extra = {}
        if rng.random() < 0.15:
            extra["department_id"] = "d777"
        if rng.random() < 0.1:
            extra["candidate_room_ids"] = [rng.choice(rooms)["id"]]
        groups.append(
            group(
                f"g{index}",
                hours,
                capacity=rng.choice([20, 30, 30, 40]),
                teacher_id=rng.choice([None, "t0", "t1", "t2"]),
                cohorts=["plan:p1:1:C1"] if rng.random() < 0.3 else [],
                needs_lab=lab,
                practice_hours=1 if lab else 0,
                **extra,
            )
        )

    return instance(
        campuses=[campus(days=[1, 2, 3, 4], day_end="11:00")],
        groups=groups,
        teachers=teachers,
        rooms=rooms,
        agreements=agreements,
    )


@pytest.mark.parametrize("seed", range(60))
def test_las_dos_fases_dicen_lo_mismo_que_el_modelo_exacto(seed):
    data = random_instance(seed)
    model_input = model_data(data)

    exact = solve_hard(model_input, time_limit=10, pool_rooms=False, tiers_by_need=False)
    pooled = solve_hard(model_input, time_limit=10, pool_rooms=True, tiers_by_need=False)

    assert exact.status != cp_model.UNKNOWN
    assert (pooled.status in FEASIBLE) == (exact.status in FEASIBLE)
    if pooled.feasible:
        # La fase B repartió los salones (si no, solve_hard habría lanzado RoomAssignmentError) sin duras.
        assert hard_violations(parse_input(data), pooled.sessions) == []
        assert all(session.room_id is not None for session in pooled.sessions)


def test_el_conjunto_cuenta_los_salones_libres_por_umbral():
    data = instance(
        rooms=[
            room("chico", capacity=25),
            room("grande", capacity=45, busy=[slot(1, "07:00", "08:00")]),
            room("lab", type="laboratorio", capacity=30),
            room("ajeno", owner_department_id="d999"),
        ],
        agreements=[
            {"room_id": "ajeno", "to_department_id": "d001", "day": 1, "starts_at": "08:00", "ends_at": "09:00"}
        ],
    )
    pool = RoomPool(ModelData.of(parse_input(data)), "C1", "d001")

    assert pool.free(1, 0) == ("chico", "lab")
    assert pool.free(1, 1) == ("chico", "lab", "ajeno", "grande")
    assert pool.union(1, 1, 35, 30) == ["lab", "ajeno", "grande"]
    assert pool.has_room(1, 0, 30, lab=True)
    assert not pool.has_room(1, 0, 31, lab=False)


def test_la_fase_b_reparte_con_emparejamiento_y_avisa_si_no_alcanza():
    """La de 20 lugares cabe en los dos salones y prefiere el aula; la de 35 solo cabe en el laboratorio de 40. Si una
    ventana explícita ya tomó el aula, no alcanza: la fase B lo dice en vez de dejar una sesión sin salón."""
    data = instance(rooms=[room("aula", capacity=30), room("lab", type="laboratorio", capacity=40)])
    pool = RoomPool(ModelData.of(parse_input(data)), "C1", "d001")
    sessions = [PooledSession("teoria", 1, 20, False), PooledSession("grande", 1, 35, False)]

    assigned = match(pool, 1, 0, sessions, set(), exclusive=True)

    assert {session.group_id: room_id for session, room_id in assigned.items()} == {"teoria": "aula", "grande": "lab"}
    with pytest.raises(RoomAssignmentError):
        match(pool, 1, 0, sessions, {"aula"}, exclusive=True)


def test_sin_hc02_la_fase_b_comparte_los_salones_tomados():
    """Sin HC-02 un salón se comparte: el laboratorio que ya tomó una ventana explícita le sirve a la práctica."""
    data = instance(rooms=[room("aula"), room("lab", type="laboratorio")])
    pool = RoomPool(ModelData.of(parse_input(data)), "C1", "d001")
    practice = PooledSession("practica", 1, 20, True)

    assert match(pool, 1, 0, [practice], {"lab"}, exclusive=False) == {practice: "lab"}


def lab_and_room_counterexample() -> dict[str, Any]:
    """El contraejemplo del Riel A: en un solo bloque, un laboratorio de 40 y un aula de 20 contra una práctica de 20
    y una teoría de 35. Cada cuenta sola pasa (una teoría de 35 cabe en un salón; una práctica de 20, en uno), pero los
    dos solo caben en el laboratorio."""
    return instance(
        campuses=[campus(days=[1], day_end="08:00")],
        groups=[group("practica", 1, capacity=20, needs_lab=True, practice_hours=1), group("teoria", 1, capacity=35)],
        rooms=[room("lab", type="laboratorio", capacity=40), room("aula", capacity=20)],
    )


@pytest.mark.parametrize("exact", [False, True], ids=["dos fases", "exacto"])
def test_el_contraejemplo_de_laboratorio_y_aula_es_infactible_al_armar_el_modelo(exact):
    model_input = model_data(lab_and_room_counterexample())

    run = solve_hard(model_input, time_limit=5, pool_rooms=not exact, tiers_by_need=not exact)

    assert run.status == cp_model.INFEASIBLE
    result = lns(model_input, time_limit=5, seed=7)
    assert len(result.unplaced) == 1
    assert hard_violations(parse_input(lab_and_room_counterexample()), result.all_sessions) == []


def without_cross_pairs(monkeypatch: pytest.MonkeyPatch) -> None:
    """Quita los pares cruzados de la cuenta de la fase A: deja solo los de teoría sola y práctica sola."""
    keep = constraints.threshold_pairs
    monkeypatch.setattr(
        constraints,
        "threshold_pairs",
        lambda theory, lab: [(a, b) for a, b in keep(theory, lab) if NONE in (a, b)],
    )


def test_sin_los_pares_cruzados_la_fase_b_no_alcanza(monkeypatch):
    """El par cruzado es el único que amarra el contraejemplo: sin él la fase A da una solución que la fase B no puede
    repartir, y `solve_hard` lo dice en vez de devolver sesiones sin salón."""
    without_cross_pairs(monkeypatch)

    run = solve_hard(model_data(lab_and_room_counterexample()), time_limit=5)

    assert run.status == cp_model.UNKNOWN
    assert run.sessions == []
    assert "Sin salón" in (run.error or "")


def tight_instance(seed: int) -> dict[str, Any]:
    """Una instancia que aprieta Hall: un día de 1 o 2 bloques, de 4 a 8 grupos de una hora de 20, 30 o 35 lugares
    (algunos con práctica de laboratorio) para 1 o 2 laboratorios y de 3 a 5 aulas de varios tamaños, sin docentes:
    solo los salones amarran."""
    rng = random.Random(1000 + seed)
    rooms = [
        room(f"lab{index}", type="laboratorio", capacity=rng.choice([25, 35, 40])) for index in range(rng.randint(1, 2))
    ]
    rooms += [room(f"aula{index}", capacity=rng.choice([25, 30, 35, 40])) for index in range(rng.randint(3, 5))]
    groups = []
    for index in range(rng.randint(4, 8)):
        lab = rng.random() < 0.4
        groups.append(
            group(
                f"g{index}",
                1,
                capacity=rng.choice([20, 30, 35]),
                needs_lab=lab,
                practice_hours=1 if lab else 0,
            )
        )

    return instance(
        campuses=[campus(days=[1], day_end=rng.choice(["08:00", "09:00"]))],
        groups=groups,
        rooms=rooms,
    )


TIGHT_SEEDS = range(60)


def test_la_familia_apretada_dice_lo_mismo_que_el_exacto_y_la_fase_b_siempre_reparte():
    """Con todos los grupos de una hora y solo los salones por amarrar, la fase A es factible exactamente cuando el
    modelo exacto lo es, la fase B reparte siempre que la A tiene solución y salen casos de los dos lados."""
    outcomes = []
    for seed in TIGHT_SEEDS:
        data = tight_instance(seed)
        model_input = model_data(data)
        exact = solve_hard(model_input, time_limit=10, pool_rooms=False, tiers_by_need=False)
        pooled = solve_hard(model_input, time_limit=10, pool_rooms=True, tiers_by_need=False)

        assert exact.status != cp_model.UNKNOWN, seed
        assert pooled.error is None, seed
        assert pooled.feasible == exact.feasible, seed
        if pooled.feasible:
            assert hard_violations(parse_input(data), pooled.sessions) == [], seed
        outcomes.append(pooled.feasible)

    # Con estas semillas: 22 factibles y 38 infactibles.
    assert outcomes.count(True) >= 15
    assert outcomes.count(False) >= 15


def test_en_la_familia_apretada_sin_pares_cruzados_la_fase_b_falla(monkeypatch):
    """La familia apretada sí ejercita los pares cruzados: sin ellos, en alguna instancia la fase A queda factible y
    la fase B no puede repartir."""
    without_cross_pairs(monkeypatch)

    failures = [
        seed
        for seed in TIGHT_SEEDS
        if solve_hard(model_data(tight_instance(seed)), time_limit=10, tiers_by_need=False).error is not None
    ]

    assert failures
