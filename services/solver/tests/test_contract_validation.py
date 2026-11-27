"""Una entrada rota se rechaza con la ruta del campo, igual que `ContractValidationTest` en PHP."""

from collections.abc import Callable
from typing import Any

import pytest

from app.contract import ContractError, parse_input, parse_output

Data = dict[str, Any]


def _set(path: list[str | int], value: Any) -> Callable[[Data], None]:
    def change(data: Data) -> None:
        target: Any = data
        for part in path[:-1]:
            target = target[part]
        target[path[-1]] = value

    return change


def _repeat_group_id(data: Data) -> None:
    data["groups"][2]["id"] = data["groups"][0]["id"]


def _drop_required(data: Data) -> None:
    del data["groups"][0]["capacity"]


BROKEN = {
    "versión distinta de 1": (_set(["version"], 2), "version", "versión no soportada"),
    "campus desconocido en un grupo": (
        _set(["groups", 1, "campus_code"], "C9"),
        "groups[1].campus_code",
        "no está en campuses",
    ),
    "id de grupo repetido": (_repeat_group_id, "groups[2].id", "repetido"),
    "docente que no está en teachers": (
        _set(["groups", 0, "teacher_id"], "no-existe"),
        "groups[0].teacher_id",
        "teachers",
    ),
    "alcance campus": (_set(["scope", "kind"], "campus"), "scope.kind", "department, career"),
    "día 7": (_set(["teachers", 0, "preferred", 0, "day"], 7), "teachers[0].preferred[0].day", "entre 1 y 6"),
    "fin antes del inicio": (
        _set(["rooms", 1, "blocks", 0, "ends_at"], "12:00"),
        "rooms[1].blocks[0].ends_at",
        "posterior",
    ),
    "peso blando negativo": (_set(["soft_weights", "SC-03"], -1), "soft_weights.SC-03", "entero de 0 en adelante"),
    "peso blando sobre el tope": (
        _set(["soft_weights", "SC-01"], 10001),
        "soft_weights.SC-01",
        "debe estar entre 0 y 10 000",
    ),
    "peso blando desconocido": (_set(["soft_weights", "SC-10"], 1), "soft_weights.SC-10", "desconocida"),
    "regla dura mal escrita": (_set(["hard_rules", 2], "HC-3"), "hard_rules[2]", "HC-"),
    "salón candidato inexistente": (
        _set(["groups", 0, "candidate_room_ids"], ["no-existe"]),
        "groups[0].candidate_room_ids[0]",
        "rooms",
    ),
    "horas semanales en cero": (
        _set(["groups", 2, "weekly_hours"], 0),
        "groups[2].weekly_hours",
        "entero de 1 en adelante",
    ),
    "horas semanales como cadena": (_set(["groups", 0, "weekly_hours"], "3"), "groups[0].weekly_hours", "entero"),
    "booleano como entero": (_set(["groups", 0, "needs_lab"], 1), "groups[0].needs_lab", "verdadero o falso"),
    "hora mal escrita": (_set(["campuses", 0, "day_start"], "7:00"), "campuses[0].day_start", "HH:MM"),
    "campo obligatorio ausente": (_drop_required, "groups[0].capacity", "es obligatorio"),
    "campus sin días": (_set(["campuses", 0, "days"], []), "campuses[0].days", "al menos un día"),
    "bloque que no divide la jornada": (
        _set(["campuses", 0, "block_minutes"], 50),
        "campuses[0].day_end",
        "no se divide en bloques",
    ),
    "campo obligatorio nulo": (_set(["run_id"], None), "run_id", "es obligatorio"),
    "préstamo de un grupo que no está": (
        _set(["agreements", 0, "group_id"], "no-existe"),
        "agreements[0].group_id",
        "groups",
    ),
    "lista que no es lista": (_set(["groups"], {"a": 1}), "groups", "lista"),
}


@pytest.mark.parametrize(("change", "path", "fragment"), list(BROKEN.values()), ids=list(BROKEN))
def test_una_entrada_rota_se_rechaza_con_la_ruta_del_campo(
    tiny_input: Data, change: Callable[[Data], None], path: str, fragment: str
):
    change(tiny_input)

    with pytest.raises(ContractError) as raised:
        parse_input(tiny_input)

    paths = [problem_path for problem_path, _ in raised.value.problems]
    assert path in paths
    message = dict(raised.value.problems)[path]
    assert fragment in message
    assert str(raised.value).startswith(f"{paths[0]}: ")


def test_las_llaves_desconocidas_se_ignoran_salvo_en_los_pesos(tiny_input: Data):
    tiny_input["extra"] = {"cualquier": "cosa"}
    tiny_input["groups"][0]["nota"] = "ignorada"

    parsed = parse_input(tiny_input)

    assert "extra" not in parsed.model_dump()


def test_la_tolerancia_de_horas_semanales_se_acepta_y_por_omision_vale_cero(tiny_input: Data):
    tiny_input["weekly_hours_tolerance"] = 30
    assert parse_input(tiny_input).weekly_hours_tolerance == 30

    del tiny_input["weekly_hours_tolerance"]
    assert parse_input(tiny_input).weekly_hours_tolerance == 0


def test_una_tolerancia_de_horas_semanales_negativa_se_rechaza(tiny_input: Data):
    tiny_input["weekly_hours_tolerance"] = -1

    with pytest.raises(ContractError) as raised:
        parse_input(tiny_input)

    assert "weekly_hours_tolerance" in [path for path, _ in raised.value.problems]


def test_los_pesos_ausentes_valen_cero(tiny_input: Data):
    del tiny_input["soft_weights"]["SC-09"]

    assert parse_input(tiny_input).soft_weights.as_dict()["SC-09"] == 0


def test_una_salida_feasible_con_violaciones_duras_se_rechaza(tiny_output: Data):
    tiny_output["hard_violations"] = [
        {"rule": "HC-01", "group_ids": [tiny_output["sessions"][0]["group_id"]], "message": "Docente empalmado."}
    ]

    with pytest.raises(ContractError) as raised:
        parse_output(tiny_output)

    assert raised.value.problems[0][0] == "hard_violations"


def test_una_salida_partial_admite_violaciones_duras(tiny_output: Data):
    tiny_output["status"] = "partial"
    tiny_output["hard_violations"] = [{"rule": "HC-01", "group_ids": ["g"], "message": "Docente empalmado."}]

    assert parse_output(tiny_output).hard_violations[0].rule == "HC-01"


def _hint(data: Data, **changes: Any) -> dict[str, Any]:
    hint = {
        "group_id": data["groups"][0]["id"],
        "day": 1,
        "starts_at": "09:00",
        "ends_at": "11:00",
        "kind": "teoria",
    }
    return {**hint, **changes}


def test_hint_sessions_por_omision_es_vacia_y_presente_se_acepta(tiny_input: Data):
    assert parse_input(tiny_input).hint_sessions == []

    tiny_input["hint_sessions"] = [_hint(tiny_input), _hint(tiny_input, room_id=tiny_input["rooms"][0]["id"])]
    parsed = parse_input(tiny_input)

    assert len(parsed.hint_sessions) == 2
    assert parsed.hint_sessions[1].room_id == tiny_input["rooms"][0]["id"]


@pytest.mark.parametrize(
    ("changes", "path", "fragment"),
    [
        ({"group_id": "no-existe"}, "hint_sessions[0].group_id", "groups"),
        ({"room_id": "no-existe"}, "hint_sessions[0].room_id", "rooms"),
        ({"starts_at": "11:00", "ends_at": "09:00"}, "hint_sessions[0].ends_at", "posterior"),
        ({"day": 7}, "hint_sessions[0].day", "entre 1 y 6"),
    ],
    ids=["grupo desconocido", "salón desconocido", "fin antes del inicio", "día 7"],
)
def test_una_pista_rota_se_rechaza_con_la_ruta_del_campo(
    tiny_input: Data, changes: dict[str, Any], path: str, fragment: str
):
    tiny_input["hint_sessions"] = [_hint(tiny_input, **changes)]

    with pytest.raises(ContractError) as raised:
        parse_input(tiny_input)

    assert fragment in dict(raised.value.problems)[path]
