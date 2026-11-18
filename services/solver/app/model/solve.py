"""La resolución con el modelo CP-SAT de punta a punta: lo que corre el motor `cpsat` del servicio (tarea 7.7;
docs/arquitectura/solver.md, «Resolución»).

1. `search.lns` programa a la mayor cantidad de grupos, sin objetivo blando (con su asignación relajada: un grupo que
   no cabe queda fuera y la corrida sigue).
2. `search.improve` busca por vecindarios con el objetivo (7.6) hasta el plazo menos la reserva final.
3. Con la reserva: cada grupo que no quedó se intenta solo, con lo demás fijo; si tampoco cabe, se revisa por qué
   (`main_reason` y las demás reglas que le quitaron opciones) y va a `unscheduled` con sus reglas. Luego la fase B
   con objetivo ajusta los salones (`room_model.py`).
4. La salida lleva el puntaje de `score.py` (el de PHP), las duras que revise `verify.py` más las de la entrada (HC-08,
   PS-08) y el estado: `feasible` si todo quedó sin duras, `partial` si no, `infeasible` si no quedó ningún grupo.
   Nunca `optimal`: la búsqueda por vecindarios no da una cota, así que no prueba que la solución sea la mejor.

El avance va de 0 a 40 al programar (los grupos programados entre los movibles, también en cada solución intermedia
de la primera vuelta), de 40 a 95 al mejorar (el plazo transcurrido, con el mejor puntaje) y a 99 al terminar los
salones; la corrida pone el 100. La cancelación se revisa al armar cada modelo, cada 0.2 s mientras CP-SAT resuelve y
entre vecindarios (`SearchLimits`).
"""

import time
from collections.abc import Callable
from dataclasses import dataclass, field

from ortools.sat.python import cp_model

from app.contract import RunStatus, SolverInput, SolverOutput
from app.contract.output import ProposedSession, RunMetrics, UnscheduledGroup

from .constraints import add_hard_constraints, input_problems
from .data import ModelData
from .objective import add_objective
from .phases import FEASIBLE, SearchLimits, run_solver
from .room_model import improve_rooms
from .rooms import RoomAssignmentError
from .score import Score, SoftScorer
from .search import Improvement, Search, _sub_input, improve, lns
from .solution import chosen_option, sessions_of
from .variables import BuildTimeout, DomainOptions, ModelTooLarge, build_variables, unit_counts
from .verify import hard_violations

#: Hasta cuántos segundos tiene la primera vuelta de `lns`.
FIRST_ROUND_SECONDS = 15.0

#: El avance al terminar de programar y al terminar de mejorar.
PLACED_PERCENT = 40
IMPROVED_PERCENT = 95

#: Por qué se quitó una opción, por regla, para los motivos de `unscheduled`.
REASONS = {
    "HC-01": "el docente ya tiene clase",
    "HC-02": "no queda salón libre",
    "HC-03": "choca con su cohorte",
    "HC-05": "no hay salón libre con lugar para el grupo",
    "HC-06": "no queda laboratorio, taller o sala de cómputo libre",
    "HC-09": "el docente no está disponible",
    "HC-10": "los salones de otro departamento no tienen préstamo",
    "HC-11": "los salones están bloqueados",
}


def first_share(time_limit: float) -> float:
    """La primera vuelta de `lns`: la cuarta parte del plazo, hasta `FIRST_ROUND_SECONDS`. Con 2 hilos programa casi
    lo mismo en 15 s que en 75, y el tiempo rinde más mejorando (7.6)."""
    return min(0.25, FIRST_ROUND_SECONDS / time_limit)


def final_reserve(time_limit: float) -> float:
    """Lo que se guarda al final para los que no quedaron y la fase B con objetivo: la décima parte, hasta 10 s."""
    return min(10.0, time_limit / 10)


@dataclass
class Phase:
    """Cómo iba la solución al terminar cada fase: segundos, grupos fuera de su patrón y puntaje."""

    name: str
    seconds: float
    off_pattern: int
    score: Score


@dataclass
class Resolution:
    """La salida del contrato y cómo iba la solución después de cada fase (para medir: `scripts/objective.py`)."""

    output: SolverOutput
    phases: list[Phase] = field(default_factory=list)


class _Progress:
    """`report(avance, mejor puntaje)` de la corrida, sin que el avance baje nunca."""

    def __init__(self, report: Callable[[int, int | None], None] | None) -> None:
        self._report = report
        self._percent = 0

    def __call__(self, percent: float, best: int | None = None) -> None:
        self._percent = max(self._percent, min(99, int(percent)))
        if self._report is not None:
            self._report(self._percent, best)


def solve(
    data: SolverInput,
    time_limit: float,
    limits: SearchLimits | None = None,
    seed: int = 0,
    report: Callable[[int, int | None], None] | None = None,
    engine: str = "cpsat",
    version: str = "",
) -> Resolution:
    """La mejor solución que se encuentra en `time_limit` segundos, como salida del contrato."""
    limits = limits or SearchLimits()
    started = time.perf_counter()
    deadline = started + time_limit
    reserve = final_reserve(time_limit)
    model_data = ModelData.of(data)
    scorer = SoftScorer(data)
    progress = _Progress(report)
    total = max(1, len(model_data.movable))
    phases: list[Phase] = []

    def mark(name: str, search: Search, sessions: list[ProposedSession]) -> Score:
        score = scorer.score(sessions)
        off_pattern = sum(tier > 0 for tier in search.tiers.values())
        phases.append(Phase(name, round(time.perf_counter() - started, 1), off_pattern, score))

        return score

    search = lns(
        model_data,
        time_limit=max(0.0, time_limit - reserve),
        limits=limits,
        seed=seed,
        first_share=first_share(time_limit),
        report=lambda placed: progress(PLACED_PERCENT * placed / total),
    )
    placed_score = mark("lns", search, search.all_sessions)
    progress(PLACED_PERCENT, placed_score.total if search.sessions else None)

    improvement = improve(
        model_data,
        search,
        deadline - reserve,
        limits=limits,
        seed=seed,
        report=lambda state, share: progress(
            PLACED_PERCENT + (IMPROVED_PERCENT - PLACED_PERCENT) * share, state.best.total
        ),
    )
    mark("improve", search, search.all_sessions)

    reasons = _complete(model_data, search, deadline - reserve / 2, limits, seed)
    sessions = improve_rooms(model_data, search.all_sessions, deadline, limits=limits)
    score = mark("improve_rooms", search, sessions)
    progress(99, score.total)

    output = _output(data, model_data, search, sessions, score, improvement, reasons, started, engine, version)

    return Resolution(output, phases)


def _complete(
    data: ModelData, search: Search, deadline: float, limits: SearchLimits, seed: int
) -> dict[str, list[str]]:
    """Cada grupo que no quedó, solo y con lo demás fijo: si cabe, se programa en su mejor opción; si no, sus motivos
    con la regla al frente, de la que más opciones le quitó a la que menos. Sin tiempo, un motivo que lo dice."""
    reasons: dict[str, list[str]] = {}
    scope = {group.id for group in data.movable}
    for group_id in list(search.unplaced):
        limits.tick()
        if time.perf_counter() >= deadline:
            reasons[group_id] = ["No se alcanzó a programar ni a revisar por qué en el plazo de la corrida."]
            continue
        found = _alone(data, search, group_id, scope, deadline, limits, seed)
        if found is not None:
            reasons[group_id] = found
    search.unplaced = [group.id for group in data.movable if group.id not in search.sessions]

    return {group_id: reasons[group_id] for group_id in search.unplaced if group_id in reasons}


def _alone(
    data: ModelData, search: Search, group_id: str, scope: set[str], deadline: float, limits: SearchLimits, seed: int
) -> list[str] | None:
    """El grupo con todos sus niveles y los demás programados fijos: `None` si se pudo programar (y queda en la
    búsqueda), o sus motivos."""
    sub = ModelData.of(_sub_input(data.source, search, {group_id}))
    model = cp_model.CpModel()
    try:
        variables = build_variables(model, sub, DomainOptions(all_tiers=True), limits.budget(deadline))
    except (BuildTimeout, ModelTooLarge):
        return ["No se alcanzó a revisar por qué no cabe en el plazo de la corrida."]
    domain = variables.groups[group_id]
    if domain.options:
        add_hard_constraints(model, variables, sub)
        model.minimize(add_objective(model, variables, sub, scope=scope).expression())
        solver = cp_model.CpSolver()
        solver.parameters.max_time_in_seconds = max(0.05, deadline - time.perf_counter())
        solver.parameters.random_seed = seed
        if run_solver(solver, model, limits) in FEASIBLE:
            option = chosen_option(solver, domain)
            try:
                sessions = sessions_of(solver, variables, sub)
            except RoomAssignmentError:
                sessions = []
            if option is not None and sessions:
                search.sessions[group_id] = sessions
                search.chosen[group_id] = (option.spread, option.block)
                search.tiers[group_id] = option.tier
                return None

        return ["Cabe solo, pero no se alcanzó a programar con los demás en el plazo de la corrida."]

    return _reasons(data, search, group_id, domain.rejected_options)


def _reasons(data: ModelData, search: Search, group_id: str, rejected: dict[str, int]) -> list[str]:
    """Los motivos de un grupo sin opciones, con la regla al frente: la que más opciones le quitó primero (la de
    `main_reason`). En las de docente y cohorte, con cuántos grupos programados choca."""
    group = data.groups[group_id]
    if not rejected:
        if not unit_counts(group, data.grids[group.campus_code], data):
            return ["HC-12: sus horas semanales no dan bloques enteros de la rejilla de su campus."]
        return ["No tiene ninguna opción en la rejilla de su campus."]
    total = sum(rejected.values())
    reasons = []
    for rule in sorted(rejected, key=lambda rule: (-rejected[rule], rule)):
        text = REASONS.get(rule, "lo impide la regla")
        others = _conflicting(data, search, group_id, rule)
        tail = f", con {others} {'grupo programado' if others == 1 else 'grupos programados'}" if others else ""
        reasons.append(f"{rule}: {text} en {rejected[rule]} de sus {total} opciones{tail}.")

    return reasons


def _conflicting(data: ModelData, search: Search, group_id: str, rule: str) -> int:
    """Cuántos grupos programados comparten con él el docente (HC-01) o una cohorte (HC-03)."""
    group = data.groups[group_id]
    if rule == "HC-01" and group.teacher_id is not None:
        return sum(
            1 for other in search.sessions if other != group_id and data.groups[other].teacher_id == group.teacher_id
        )
    if rule == "HC-03":
        cohorts = set(group.cohorts)
        return sum(1 for other in search.sessions if other != group_id and cohorts & set(data.groups[other].cohorts))

    return 0


def _output(
    data: SolverInput,
    model_data: ModelData,
    search: Search,
    sessions: list[ProposedSession],
    score: Score,
    improvement: Improvement,
    reasons: dict[str, list[str]],
    started: float,
    engine: str,
    version: str,
) -> SolverOutput:
    violations = hard_violations(data, sessions) + input_problems(model_data)
    unscheduled = [
        UnscheduledGroup(
            group_id=group_id, reasons=reasons.get(group_id, ["No se alcanzó a programar en el plazo de la corrida."])
        )
        for group_id in search.unplaced
    ]
    if model_data.movable and not search.sessions:
        status = RunStatus.INFEASIBLE
    elif unscheduled or violations:
        status = RunStatus.PARTIAL
    else:
        status = RunStatus.FEASIBLE
    placed = len(search.sessions)
    off_pattern = sum(tier > 0 for tier in search.tiers.values())
    largest = max(search.steps, key=lambda step: step.variables, default=None)
    log = [
        f"Programó {placed} de {len(model_data.movable)} grupos; {off_pattern} fuera de su patrón de días (PS-04).",
        f"Búsqueda con objetivo: revisó {improvement.steps} vecindarios y aceptó {improvement.accepted}; el puntaje "
        f"blando pasó de {improvement.initial.total} a {improvement.best.total} y, con los salones, a {score.total}.",
        "No se probó el óptimo: la búsqueda por vecindarios no da una cota, así que la salida nunca es «optimal».",
    ]
    if unscheduled:
        log.append(f"{len(unscheduled)} grupos sin programar; sus motivos van en «unscheduled».")

    return SolverOutput(
        version=data.version,
        run_id=data.run_id,
        status=status,
        sessions=sessions,
        unscheduled=unscheduled,
        hard_violations=violations,
        soft_score=score.to_contract(),
        metrics=RunMetrics(
            elapsed_seconds=round(time.perf_counter() - started, 3),
            engine=engine,
            engine_version=version,
            variables=largest.variables if largest else 0,
            constraints=largest.constraints if largest else 0,
            iterations=len(search.steps) + improvement.steps,
            intermediate_solutions=improvement.accepted + (1 if search.sessions else 0),
        ),
        log=log,
    )
