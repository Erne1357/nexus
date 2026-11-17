"""El motor de prueba del servicio: el equivalente de `FixedEngine` en PHP.

Sin buscar nada, pone cada grupo movible en horas seguidas desde el inicio de la jornada de su campus, un día por
grupo según su orden, en su primer salón candidato o en el primer salón de su campus. Un grupo que no cabe en la
jornada queda sin programar. No revisa reglas duras ni calcula el puntaje, así que nunca dice `feasible`: devuelve
`partial` y lo avisa en el log. Existe para que la API, el cliente de PHP y la pantalla de corridas se construyan
antes que el modelo CP-SAT (tareas 7.4 a 7.7), que lo reemplaza.
"""

import time

from app.contract import SOFT_RULES, RunStatus, SessionKind, SolverInput, SolverOutput
from app.contract.input import CampusGrid, GroupInput
from app.contract.output import (
    ProposedSession,
    RuleScores,
    RunMetrics,
    SoftScore,
    UnscheduledGroup,
)

from .base import RunContext


class TrivialEngine:
    key = "trivial"
    version = "0.1.0"

    def solve(self, data: SolverInput, context: RunContext) -> SolverOutput:
        started = time.perf_counter()
        campuses = {campus.code: campus for campus in data.campuses}
        movable = [group for group in data.groups if group.movable]

        sessions: list[ProposedSession] = []
        unscheduled: list[UnscheduledGroup] = []

        for index, group in enumerate(movable):
            context.check_cancelled()
            grid = campuses[group.campus_code]
            start = _minutes(grid.day_start)
            end = start + group.weekly_hours * 60

            if end > _minutes(grid.day_end):
                unscheduled.append(
                    UnscheduledGroup(
                        group_id=group.id,
                        reasons=["El motor de prueba no lo acomoda en la jornada de su campus."],
                    )
                )
            else:
                sessions.extend(_place(data, group, grid, start, grid.days[index % len(grid.days)]))

            context.report((index + 1) * 100 // len(movable), None)

        return SolverOutput(
            version=data.version,
            run_id=data.run_id,
            status=RunStatus.PARTIAL,
            sessions=sessions,
            unscheduled=unscheduled,
            hard_violations=[],
            soft_score=SoftScore(total=0, by_rule=RuleScores.model_validate(dict.fromkeys(SOFT_RULES, 0))),
            metrics=RunMetrics(
                elapsed_seconds=round(time.perf_counter() - started, 3),
                engine=self.key,
                engine_version=self.version,
            ),
            log=["Motor de prueba del servicio"],
        )


def _place(data: SolverInput, group: GroupInput, grid: CampusGrid, start: int, day: int) -> list[ProposedSession]:
    room_id = None
    if grid.uses_rooms:
        room_id = group.candidate_room_ids[0] if group.candidate_room_ids else _first_room(data, group.campus_code)

    theory_hours = group.weekly_hours - group.practice_hours
    sessions = []
    for hour in range(group.weekly_hours):
        if not grid.uses_rooms:
            kind = SessionKind.VIRTUAL
        else:
            kind = SessionKind.THEORY if hour < theory_hours else SessionKind.PRACTICE
        sessions.append(
            ProposedSession(
                group_id=group.id,
                day=day,
                starts_at=_clock(start + hour * 60),
                ends_at=_clock(start + (hour + 1) * 60),
                room_id=room_id,
                kind=kind,
                changed=True,
            )
        )

    return sessions


def _first_room(data: SolverInput, campus_code: str) -> str | None:
    return next((room.id for room in data.rooms if room.campus_code == campus_code), None)


def _minutes(clock: str) -> int:
    hours, minutes = clock.split(":")

    return int(hours) * 60 + int(minutes)


def _clock(minutes: int) -> str:
    return f"{minutes // 60:02d}:{minutes % 60:02d}"
