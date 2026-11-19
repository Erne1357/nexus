"""La fase B con objetivo (`room_model.py`, tarea 7.6): con el horario fijo, los salones que bajan SC-03 y SC-06.

Cada prueba parte de una salida con salones que penalizan, calculada a mano, y revisa que el modelo los mejore sin
mover horas ni romper reglas duras.
"""

import time
from typing import Any

from app.contract import SOFT_RULES, parse_input
from app.contract.output import ProposedSession
from app.model.data import ModelData
from app.model.phases import SearchLimits
from app.model.room_model import improve_rooms
from app.model.score import SoftScorer
from app.model.verify import hard_violations
from tests.instances import campus, group, instance, room, session, teacher

DAY = [campus(day_end="14:00")]


def weights(**values: int) -> dict[str, int]:
    return {rule: values.get(rule.replace("-", "_").lower(), 0) for rule in SOFT_RULES}


def one_hour(group_id: str, day: int, start: str, room_id: str, kind: str = "teoria") -> ProposedSession:
    end = f"{int(start[:2]) + 1:02d}:{start[3:]}"

    return ProposedSession(
        group_id=group_id, day=day, starts_at=start, ends_at=end, room_id=room_id, kind=kind, changed=True
    )


def improved(data: dict[str, Any], sessions: list[ProposedSession], **options: Any) -> list[ProposedSession]:
    source = parse_input(data)
    result = improve_rooms(ModelData.of(source), sessions, time.perf_counter() + 5, **options)
    assert hard_violations(source, result) == []
    assert [(s.group_id, s.day, s.starts_at, s.kind) for s in result] == [
        (s.group_id, s.day, s.starts_at, s.kind) for s in sessions
    ]

    return result


def score(data: dict[str, Any], sessions: list[ProposedSession]):
    return SoftScorer(parse_input(data)).score(sessions)


def rooms_of(sessions: list[ProposedSession], group_id: str) -> set[str | None]:
    return {session.room_id for session in sessions if session.group_id == group_id}


def test_sc03_lleva_la_materia_a_su_salon_por_defecto():
    """SC-03: g1 tiene a r2 por defecto y va en r1; g2 ocupa r2 a la misma hora y no tiene salón por defecto. El
    modelo los cambia: de 5 (una hora fuera, peso 5) a 0."""
    data = instance(
        campuses=DAY,
        groups=[group("g1", 1), group("g2", 1)],
        rooms=[room("r1"), room("r2")],
        subject_room_defaults=[{"subject_code": "MAT-g1", "campus_code": "C1", "room_id": "r2", "priority": 1}],
        soft_weights=weights(sc_03=5),
    )
    sessions = [one_hour("g1", 1, "07:00", "r1"), one_hour("g2", 1, "07:00", "r2")]
    assert score(data, sessions).by_rule["SC-03"] == 5

    result = improved(data, sessions)

    assert score(data, result).by_rule["SC-03"] == 0
    assert rooms_of(result, "g1") == {"r2"}


def test_al_cambiar_de_salon_changed_se_vuelve_a_calcular_contra_el_horario_actual():
    """`changed` incluye el salón (PS-07), así que la fase B con objetivo lo vuelve a calcular cuando mueve una sesión.
    g1 está hoy en r1 lunes y martes a las 07:00 y tiene a r2 por defecto (SC-03, peso 10): sale en r2 y cambiada. Al
    revés, si r1 es su salón por defecto y la propuesta lo puso en r2, regresa a r1 y ya no está cambiada."""
    current = [session(1, "07:00", "08:00", "r1"), session(2, "07:00", "08:00", "r1")]

    def data_with(default: str) -> dict[str, Any]:
        return instance(
            campuses=DAY,
            groups=[group("g1", 2, current_sessions=current)],
            rooms=[room("r1"), room("r2")],
            subject_room_defaults=[{"subject_code": "MAT-g1", "campus_code": "C1", "room_id": default, "priority": 1}],
            soft_weights=weights(sc_03=10),
        )

    def proposed(room_id: str, changed: bool) -> list[ProposedSession]:
        return [one_hour("g1", day, "07:00", room_id).model_copy(update={"changed": changed}) for day in (1, 2)]

    moved = improved(data_with("r2"), proposed("r1", changed=False))
    assert [(s.room_id, s.changed) for s in moved] == [("r2", True), ("r2", True)]
    back = improved(data_with("r1"), proposed("r2", changed=True))
    assert [(s.room_id, s.changed) for s in back] == [("r1", False), ("r1", False)]


def test_el_modelo_de_salones_de_un_dia_respeta_el_tope_de_variables():
    """`SOLVER_MAX_MODEL_VARIABLES` también vale para el modelo de salones de cada día: si lo pasa, el día se queda con
    los salones del emparejamiento (el caso de SC-03 de arriba, que sin tope sí cambia)."""
    data = instance(
        campuses=DAY,
        groups=[group("g1", 1), group("g2", 1)],
        rooms=[room("r1"), room("r2")],
        subject_room_defaults=[{"subject_code": "MAT-g1", "campus_code": "C1", "room_id": "r2", "priority": 1}],
        soft_weights=weights(sc_03=5),
    )
    sessions = [one_hour("g1", 1, "07:00", "r1"), one_hour("g2", 1, "07:00", "r2")]

    assert improved(data, sessions, limits=SearchLimits(max_variables=1)) == sessions
    assert rooms_of(improved(data, sessions, limits=SearchLimits(max_variables=4)), "g1") == {"r2"}


def test_sc06_junta_en_un_edificio_las_sesiones_pegadas_del_docente():
    """SC-06: el docente da g1 a las 07:00 en r1 (b1) y g2 a las 08:00 en r2 (b2): un cambio, peso 2. Hay otro salón
    libre en b1 (r3): el modelo deja a los dos en b1."""
    data = instance(
        campuses=DAY,
        groups=[group("g1", 1, teacher_id="t1"), group("g2", 1, teacher_id="t1")],
        teachers=[teacher("t1")],
        rooms=[room("r1", building_id="b1"), room("r2", building_id="b2"), room("r3", building_id="b1")],
        soft_weights=weights(sc_06=2),
    )
    sessions = [one_hour("g1", 1, "07:00", "r1"), one_hour("g2", 1, "08:00", "r2")]
    assert score(data, sessions).by_rule["SC-06"] == 2

    result = improved(data, sessions)

    assert score(data, result).by_rule["SC-06"] == 0


def test_sc06_con_una_sesion_fija_del_docente_se_va_a_su_edificio():
    """La sesión pegada es de un grupo fijo en b2: el movible se pasa a b2."""
    data = instance(
        campuses=DAY,
        groups=[
            group(
                "fijo",
                1,
                teacher_id="t1",
                movable=False,
                fixed_sessions=[
                    {"day": 1, "starts_at": "08:00", "ends_at": "09:00", "kind": "teoria", "room_id": "r2"}
                ],
            ),
            group("g1", 1, teacher_id="t1"),
        ],
        teachers=[teacher("t1")],
        rooms=[room("r1", building_id="b1"), room("r2", building_id="b2"), room("r3", building_id="b2")],
        soft_weights=weights(sc_06=2),
    )

    result = improved(data, [one_hour("g1", 1, "07:00", "r1")])

    assert rooms_of(result, "g1") <= {"r2", "r3"}


def test_la_practica_se_queda_en_un_espacio_de_practica():
    """HC-06: la hora de práctica de g1 está en el laboratorio r1 (b1); aunque el docente tiene una sesión pegada en
    b2, la práctica no se va al aula de b2. Con otro laboratorio en b2, sí."""
    lab = group("g1", 1, teacher_id="t1", needs_lab=True, practice_hours=1)
    base = {
        "campuses": DAY,
        "groups": [lab, group("g2", 1, teacher_id="t1")],
        "teachers": [teacher("t1")],
        "soft_weights": weights(sc_06=2),
    }
    sessions = [one_hour("g1", 1, "07:00", "r1", kind="practica"), one_hour("g2", 1, "08:00", "r2")]

    only_aula = instance(rooms=[room("r1", type="laboratorio", building_id="b1"), room("r2", building_id="b2")], **base)
    result = improved(only_aula, sessions)
    assert rooms_of(result, "g1") == {"r1"}

    with_lab = instance(
        rooms=[
            room("r1", type="laboratorio", building_id="b1"),
            room("r2", building_id="b2"),
            room("r3", type="laboratorio", building_id="b2"),
        ],
        **base,
    )
    result = improved(with_lab, sessions)
    assert score(with_lab, result).by_rule["SC-06"] == 0
    assert rooms_of(result, "g1") <= {"r1", "r3"}


def test_sin_peso_en_sc03_ni_sc06_no_toca_nada_y_con_grupos_solo_mueve_esos():
    data = instance(
        campuses=DAY,
        groups=[group("g1", 1, teacher_id="t1"), group("g2", 1, teacher_id="t1")],
        teachers=[teacher("t1")],
        rooms=[room("r1", building_id="b1"), room("r2", building_id="b2"), room("r3", building_id="b1")],
        soft_weights=weights(sc_06=0, sc_04=4),
    )
    sessions = [one_hour("g1", 1, "07:00", "r1"), one_hour("g2", 1, "08:00", "r2")]

    assert improved(data, sessions) == sessions
    with_weight = {**data, "soft_weights": weights(sc_06=2)}
    assert rooms_of(improved(with_weight, sessions, groups={"g1"}), "g2") == {"r2"}
