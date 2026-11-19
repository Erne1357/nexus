"""Instancias mínimas del contrato, armadas a mano para las pruebas del modelo.

`instance()` da una entrada válida con un campus `C1` (lunes y martes de 07:00 a 10:00, bloques de 60 min), sin
grupos, docentes ni salones; `group()`, `teacher()`, `room()` y `slot()` arman las piezas con valores que no estorban.
Todo es un `dict` del JSON del contrato, así que cada prueba lo cambia como necesita y lo valida con `model_data()`.
"""

import copy
from typing import Any

from app.contract import parse_input
from app.model.data import HARD_RULES, ModelData

DEPARTMENT = "d001"


def instance(**overrides: Any) -> dict[str, Any]:
    data: dict[str, Any] = {
        "version": 1,
        "run_id": "prueba",
        "term": {"code": "20271"},
        "modality": {"code": "PRES"},
        "campuses": [campus()],
        "time_limit_seconds": 10,
        "scope": {"kind": "department", "id": DEPARTMENT},
        "travel_minutes": 60,
        "weekly_hours_tolerance": 0,
        "groups": [],
        "teachers": [],
        "rooms": [],
        "agreements": [],
        "subject_room_defaults": [],
        "hard_rules": list(HARD_RULES),
        "soft_weights": {},
    }
    data.update(copy.deepcopy(overrides))

    return data


def campus(code: str = "C1", **overrides: Any) -> dict[str, Any]:
    return {
        "code": code,
        "block_minutes": 60,
        "day_start": "07:00",
        "day_end": "10:00",
        "days": [1, 2],
        "uses_rooms": True,
        **overrides,
    }


def group(group_id: str, weekly_hours: int = 2, **overrides: Any) -> dict[str, Any]:
    return {
        "id": group_id,
        "campus_code": "C1",
        "subject_code": f"MAT-{group_id}",
        "department_id": DEPARTMENT,
        "owner_career_id": "c001",
        "study_plan_id": "p001",
        "semester": 1,
        "weekly_hours": weekly_hours,
        "practice_hours": 0,
        "needs_lab": False,
        "capacity": 30,
        "cohorts": [],
        "fixed_sessions": [],
        "candidate_room_ids": [],
        "movable": True,
        "current_sessions": [],
        **overrides,
    }


def teacher(teacher_id: str, **overrides: Any) -> dict[str, Any]:
    return {"id": teacher_id, "remaining_hours": 40, "honorarios": False, **overrides}


def room(room_id: str, **overrides: Any) -> dict[str, Any]:
    return {"id": room_id, "campus_code": "C1", "type": "aula", "capacity": 40, "building_id": "b001", **overrides}


def slot(day: int, starts_at: str, ends_at: str, **extra: Any) -> dict[str, Any]:
    return {"day": day, "starts_at": starts_at, "ends_at": ends_at, **extra}


def session(day: int, starts_at: str, ends_at: str, room_id: str | None = None, kind: str = "teoria") -> dict[str, Any]:
    data = {"day": day, "starts_at": starts_at, "ends_at": ends_at, "kind": kind}
    if room_id is not None:
        data["room_id"] = room_id

    return data


def model_data(data: dict[str, Any]) -> ModelData:
    return ModelData.of(parse_input(data))
