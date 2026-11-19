"""La descomposición (tarea 7.8; docs/arquitectura/solver.md, «Descomposición del problema»): la segunda corrida de una
secuencia recibe lo aceptado de la primera como grupos con `movable = false` y sus `fixed_sessions`, y las respeta.

El motor no sabe que hay una secuencia: para él, lo fijo es ocupación de su docente, de sus salones y de sus cohortes.
Estas pruebas lo revisan con el motor completo (`CpSatEngine`), con un verificador propio de empalmes contra lo fijo
(`_clashes_with_fixed`) además de `hard_violations`, para no depender solo del verificador del servicio.
"""

import json
from typing import Any

from app.contract import RunStatus, SolverInput, parse_input
from app.contract.output import ProposedSession
from app.engine import CpSatEngine, RunContext
from app.model.verify import hard_violations
from tests.conftest import fixtures_dir
from tests.instances import campus, group, instance, room, session, teacher


def context() -> RunContext:
    return RunContext(threads=1)


def _minutes(clock: str) -> int:
    hours, minutes = clock.split(":")

    return int(hours) * 60 + int(minutes)


def _fixed(data: dict[str, Any], sessions: dict[str, list[dict[str, Any]]], group_ids: set[str]) -> dict[str, Any]:
    """La entrada con los grupos de `group_ids` ya aceptados: fijos, con sus sesiones de `sessions`."""
    data = json.loads(json.dumps(data))
    for entry in data["groups"]:
        if entry["id"] in group_ids:
            entry.update(movable=False, fixed_sessions=sessions[entry["id"]], current_sessions=[])

    return data


def _accepted(sessions: list[ProposedSession]) -> dict[str, list[dict[str, Any]]]:
    """Las sesiones de una salida como `fixed_sessions` de la entrada siguiente, por grupo."""
    accepted: dict[str, list[dict[str, Any]]] = {}
    for proposed in sessions:
        accepted.setdefault(proposed.group_id, []).append(
            session(proposed.day, proposed.starts_at, proposed.ends_at, proposed.room_id, proposed.kind.value)
        )

    return accepted


def _clashes_with_fixed(data: SolverInput, sessions: list[ProposedSession]) -> list[str]:
    """Cada sesión propuesta que se empalma con una fija que comparte docente, salón o cohorte con su grupo."""
    groups = {entry.id: entry for entry in data.groups}
    clashes = []
    for proposed in sessions:
        mine = groups[proposed.group_id]
        start, end = _minutes(proposed.starts_at), _minutes(proposed.ends_at)
        for other in data.groups:
            if other.movable:
                continue
            for slot in other.fixed_sessions:
                if slot.day != proposed.day or not (start < _minutes(slot.ends_at) and _minutes(slot.starts_at) < end):
                    continue
                shares = [
                    "docente" if mine.teacher_id is not None and mine.teacher_id == other.teacher_id else "",
                    "salón" if proposed.room_id is not None and proposed.room_id == slot.room_id else "",
                    "cohorte" if set(mine.cohorts) & set(other.cohorts) else "",
                ]
                if any(shares):
                    shared = ", ".join(filter(None, shares))
                    clashes.append(f"{proposed.group_id} con {other.id} el día {slot.day}: {shared}")

    return clashes


def _blocks(sessions: list[ProposedSession], group_id: str) -> list[str]:
    """Los bloques de una hora de un grupo, como «día hh:mm»."""
    blocks = []
    for proposed in sessions:
        if proposed.group_id == group_id:
            for at in range(_minutes(proposed.starts_at), _minutes(proposed.ends_at), 60):
                blocks.append(f"{proposed.day} {at // 60:02d}:{at % 60:02d}")

    return sorted(blocks)


def test_la_segunda_corrida_solo_usa_lo_que_dejan_libre_las_sesiones_fijas():
    """De lunes a viernes de 07:00 a 09:00 (dos bloques por día). Lo aceptado de la primera corrida (`aceptado`, de t1,
    de la cohorte del plan y en r1) ocupa las 07:00 de los cinco días. `mismo_docente` (t1 y la cohorte, solo en r2) y
    `mismo_salon` (otro docente y otra cohorte, solo en r1) tienen 3 horas, una por día a la misma hora (PS-01, PS-02):
    solo caben a las 08:00, y ninguno mueve lo fijo."""
    cohort = ["plan:p001:1:C1"]
    data = parse_input(
        instance(
            campuses=[campus(days=[1, 2, 3, 4, 5], day_end="09:00")],
            groups=[
                group(
                    "aceptado",
                    5,
                    teacher_id="t1",
                    cohorts=cohort,
                    movable=False,
                    fixed_sessions=[session(day, "07:00", "08:00", "r1") for day in range(1, 6)],
                ),
                group("mismo_docente", 3, teacher_id="t1", cohorts=cohort, candidate_room_ids=["r2"]),
                group("mismo_salon", 3, teacher_id="t2", cohorts=["plan:p002:1:C1"], candidate_room_ids=["r1"]),
            ],
            teachers=[teacher("t1"), teacher("t2")],
            rooms=[room("r1"), room("r2")],
        )
    )

    output = CpSatEngine().solve(data, context())

    assert output.status is RunStatus.FEASIBLE
    assert {proposed.group_id for proposed in output.sessions} == {"mismo_docente", "mismo_salon"}
    for group_id in ("mismo_docente", "mismo_salon"):
        blocks = _blocks(output.sessions, group_id)
        assert len(blocks) == 3
        assert all(block.endswith(" 08:00") for block in blocks)
    assert {proposed.room_id for proposed in output.sessions if proposed.group_id == "mismo_salon"} == {"r1"}
    assert _clashes_with_fixed(data, output.sessions) == []
    assert hard_violations(data, output.sessions) == []


def test_en_secuencia_sobre_medium_la_segunda_carrera_respeta_lo_aceptado_de_la_primera():
    """`medium` en dos corridas, como dos cargas: primero los grupos de la carrera c001 (los de c002 entran fijos y sin
    sesiones, como una carga que todavía no corre); después los de c002, con lo de c001 aceptado y fijo. Comparten el
    docente t002, la cohorte del tronco común (`plan:p002:1:C1`), el paquete k001 y los salones. La segunda corrida no
    propone nada de lo fijo, lo programa todo y nada choca con lo aceptado."""
    source = json.loads((fixtures_dir() / "v1" / "medium" / "input.json").read_text())
    movable = [entry for entry in source["groups"] if entry["movable"]]
    first_career = {entry["id"] for entry in movable if entry["owner_career_id"] == "c001"}
    second_career = {entry["id"] for entry in movable if entry["owner_career_id"] == "c002"}

    first_input = parse_input(_fixed(source, {group_id: [] for group_id in second_career}, second_career))
    first = CpSatEngine().solve(first_input, context())

    assert first.status is RunStatus.FEASIBLE
    assert {proposed.group_id for proposed in first.sessions} == first_career

    second_input = parse_input(_fixed(source, _accepted(first.sessions), first_career))
    second = CpSatEngine().solve(second_input, context())

    assert second.status is RunStatus.FEASIBLE
    assert second.unscheduled == []
    assert {proposed.group_id for proposed in second.sessions} == second_career
    assert _clashes_with_fixed(second_input, second.sessions) == []
    assert hard_violations(second_input, second.sessions) == []
