"""La resolución con el modelo CP-SAT de punta a punta: lo que corre el motor `cpsat` del servicio (tarea 7.7;
docs/arquitectura/solver.md, «Resolución»).

1. `search.lns` programa a la mayor cantidad de grupos, sin objetivo blando (con su asignación relajada: un grupo que
   no cabe queda fuera y la corrida sigue).
2. `search.improve` busca por vecindarios con el objetivo (7.6) hasta el plazo menos la reserva final.
3. Con la reserva (`_complete`): un solo modelo con todos los que no quedaron y lo demás fijo da, de cada uno, las
   opciones que le quitó cada regla. Los que no tienen ninguna van a `unscheduled` con sus reglas (la primera es la de
   `main_reason`); los que sí, se intentan uno por uno, cada uno con su parte del tiempo que queda, primero con el
   menor nivel del patrón (PS-10). Luego la fase B con objetivo ajusta los salones (`room_model.py`).
4. La salida lleva el puntaje de `score.py` (el de PHP), las duras que revise `verify.py` (que ya trae HC-08 de la
   entrada, PS-08) y el estado: `feasible` si todo quedó sin duras; `infeasible` si no quedó ningún grupo y a todos
   los que faltan se lo impiden sus reglas; si no, `partial`, también cuando lo que faltó fue tiempo. Nunca `optimal`:
   la búsqueda por vecindarios no da una cota, así que no prueba que la solución sea la mejor. Un `MODEL_INVALID` de
   CP-SAT no se esconde: lanza `InvalidModel` y la corrida queda `failed` con su mensaje.

El avance va de 0 a 40 al programar (los grupos programados entre los movibles, también en cada solución intermedia
de la primera vuelta), de 40 a 95 al mejorar (el plazo transcurrido) y a 99 al terminar los salones; la corrida pone el
100. El mejor puntaje va con el avance solo cuando todos los grupos están programados: antes, `None`. La cancelación se
revisa al armar cada modelo, cada 0.2 s mientras CP-SAT resuelve y entre vecindarios (`SearchLimits`).
"""

import time
from collections.abc import Callable
from dataclasses import dataclass, field

from ortools.sat.python import cp_model

from app.contract import RunStatus, SolverInput, SolverOutput
from app.contract.output import ProposedSession, RunMetrics, UnscheduledGroup

from .constraints import add_hard_constraints
from .data import ModelData
from .objective import add_objective
from .phases import FEASIBLE, SearchLimits, run_solver
from .room_model import improve_rooms
from .rooms import RoomAssignmentError
from .score import Score, SoftScorer
from .search import Improvement, Search, _sub_input, improve, lns
from .solution import chosen_option, sessions_of
from .variables import BuildBudget, BuildTimeout, DomainOptions, ModelTooLarge, build_variables, unit_counts
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
class Unplaced:
    """Por qué un grupo quedó sin programar: sus motivos, con la regla al frente, y si se lo impiden sus reglas (no
    tiene opciones, o CP-SAT probó que no cabe con los demás fijos) o si faltó tiempo."""

    reasons: list[str]
    by_rules: bool


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
    progress(PLACED_PERCENT, _best(search, placed_score))

    improvement = improve(
        model_data,
        search,
        deadline - reserve,
        limits=limits,
        seed=seed,
        report=lambda state, share: progress(
            PLACED_PERCENT + (IMPROVED_PERCENT - PLACED_PERCENT) * share, _best(search, state.best)
        ),
    )
    mark("improve", search, search.all_sessions)

    unplaced = _complete(model_data, search, deadline - reserve / 2, limits, seed)
    sessions = improve_rooms(model_data, search.all_sessions, deadline, limits=limits, seed=seed)
    score = mark("improve_rooms", search, sessions)
    progress(99, _best(search, score))

    output = _output(data, model_data, search, sessions, score, improvement, unplaced, started, engine, version)

    return Resolution(output, phases)


def _best(search: Search, score: Score) -> int | None:
    """El mejor puntaje para el avance: solo con todos los grupos programados (antes, un puntaje de menos grupos se ve
    mejor de lo que es)."""
    return None if search.unplaced else score.total


#: Lo que dio intentar programar a un grupo solo.
PLACED, NO_OPTIONS, DOES_NOT_FIT, NO_TIME = "placed", "no_options", "does_not_fit", "no_time"


def _complete(data: ModelData, search: Search, deadline: float, limits: SearchLimits, seed: int) -> dict[str, Unplaced]:
    """Los que no quedaron, con lo demás fijo. Primero un solo modelo con todos ellos: el dominio de cada uno solo
    depende de lo fijo, así que da las opciones que le quitó cada regla a todos a la vez, sin armar la entrada una vez
    por grupo. Se arma sin plazo (es uno solo y de eso salen los motivos), con el tope de variables y la cancelación.
    Los que no tienen opciones se quedan con sus reglas; los demás se intentan uno por uno (`_alone`), cada uno con su
    parte del tiempo que queda. Todo grupo sin lugar lleva su motivo."""
    pending = list(search.unplaced)
    if not pending:
        return {}
    limits.tick()
    shared = ModelData.of(_sub_input(data.source, search, set(pending)))
    budget = BuildBudget(max_variables=limits.max_variables, check_cancelled=limits.check_cancelled)
    try:
        domains = build_variables(cp_model.CpModel(), shared, DomainOptions(all_tiers=True), budget).groups
    except ModelTooLarge as error:
        return {
            group_id: Unplaced([f"No se pudo revisar por qué no cabe: {error}"], by_rules=False) for group_id in pending
        }
    found: dict[str, Unplaced] = {}
    candidates = [group_id for group_id in pending if domains[group_id].options]
    for group_id in pending:
        if not domains[group_id].options:
            found[group_id] = Unplaced(_reasons(data, search, group_id, domains[group_id].rejected_options), True)
    scope = {group.id for group in data.movable}
    for index, group_id in enumerate(candidates):
        limits.tick()
        now = time.perf_counter()
        share = (deadline - now) / (len(candidates) - index)
        outcome, reasons = (
            (NO_TIME, []) if share <= 0 else _alone(data, search, group_id, scope, now + share, limits, seed)
        )
        if outcome == PLACED:
            continue
        if outcome == NO_TIME:
            options = len(domains[group_id].options)
            rules = _reasons(data, search, group_id, domains[group_id].rejected_options, available=options)
            reasons = [
                f"No se alcanzó a programar en el plazo de la corrida: le quedan {options} opciones libres con los "
                "demás grupos fijos.",
                *rules,
            ]
        found[group_id] = Unplaced(reasons, by_rules=outcome != NO_TIME)
    search.unplaced = [group.id for group in data.movable if group.id not in search.sessions]

    return {group_id: found[group_id] for group_id in search.unplaced}


def _alone(
    data: ModelData, search: Search, group_id: str, scope: set[str], deadline: float, limits: SearchLimits, seed: int
) -> tuple[str, list[str]]:
    """El grupo con todos sus niveles y los demás programados fijos. Si cabe, queda en la búsqueda (`PLACED`). Es
    lexicográfico (PS-10): una resolución busca el menor nivel del patrón y otra, con ese nivel como tope, el menor
    puntaje; si la segunda no termina, se queda con la primera. Si no cabe, sus motivos: sin opciones (`NO_OPTIONS`) o
    CP-SAT probó que ninguna sirve con los demás (`DOES_NOT_FIT`). `NO_TIME` si no alcanzó el plazo."""
    sub = ModelData.of(_sub_input(data.source, search, {group_id}))
    model = cp_model.CpModel()
    budget = limits.budget(deadline)
    try:
        variables = build_variables(model, sub, DomainOptions(all_tiers=True), budget)
        domain = variables.groups[group_id]
        if not domain.options:
            return NO_OPTIONS, _reasons(data, search, group_id, domain.rejected_options)
        add_hard_constraints(model, variables, sub)
        objective = add_objective(model, variables, sub, scope=scope, budget=budget)
    except (BuildTimeout, ModelTooLarge):
        return NO_TIME, []
    literals = [option.literal for option in domain.options]
    tiers = cp_model.LinearExpr.weighted_sum(literals, [option.tier for option in domain.options])
    model.minimize(tiers)
    first = _run(model, (time.perf_counter() + deadline) / 2, limits, seed)
    if first.status == cp_model.INFEASIBLE:
        rules = _reasons(data, search, group_id, domain.rejected_options, available=len(domain.options))
        return DOES_NOT_FIT, [
            f"No cabe con los grupos ya programados: ninguna de sus {len(domain.options)} opciones libres sirve con "
            "ellos.",
            *rules,
        ]
    if first.status not in FEASIBLE:
        return NO_TIME, []
    for literal in literals:
        model.add_hint(literal, first.solver.boolean_value(literal))
    model.add(tiers <= int(first.solver.value(tiers)))
    model.minimize(objective.expression())
    second = _run(model, deadline, limits, seed)
    solver = second.solver if second.status in FEASIBLE else first.solver
    option = chosen_option(solver, domain)
    try:
        sessions = sessions_of(solver, variables, sub)
    except RoomAssignmentError:
        return NO_TIME, []
    if option is None or not sessions:
        return NO_TIME, []
    search.sessions[group_id] = sessions
    search.chosen[group_id] = (option.spread, option.block)
    search.tiers[group_id] = option.tier

    return PLACED, []


@dataclass
class _Solved:
    solver: cp_model.CpSolver
    status: int


def _run(model: cp_model.CpModel, deadline: float, limits: SearchLimits, seed: int) -> _Solved:
    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = max(0.05, deadline - time.perf_counter())
    solver.parameters.random_seed = seed

    return _Solved(solver, run_solver(solver, model, limits))


def _reasons(data: ModelData, search: Search, group_id: str, rejected: dict[str, int], available: int = 0) -> list[str]:
    """Los motivos de un grupo, con la regla al frente: la que más opciones le quitó primero (la de `main_reason`), de
    sus opciones en total (`available` son las que le quedan). En las de docente y cohorte, con cuántos grupos
    programados choca."""
    group = data.groups[group_id]
    if not rejected:
        if available:
            return []
        if not unit_counts(group, data.grids[group.campus_code], data):
            return ["HC-12: sus horas semanales no dan bloques enteros de la rejilla de su campus."]
        return ["No tiene ninguna opción en la rejilla de su campus."]
    total = sum(rejected.values()) + available
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
    unplaced: dict[str, Unplaced],
    started: float,
    engine: str,
    version: str,
) -> SolverOutput:
    # `verify.py` ya trae las duras de la entrada (HC-08, PS-08): una vez por grupo.
    violations = hard_violations(data, sessions)
    missing = Unplaced(["No se alcanzó a programar en el plazo de la corrida."], by_rules=False)
    entries = {group_id: unplaced.get(group_id, missing) for group_id in search.unplaced}
    unscheduled = [UnscheduledGroup(group_id=group_id, reasons=entry.reasons) for group_id, entry in entries.items()]
    status = run_status(bool(model_data.movable), bool(search.sessions), list(entries.values()), bool(violations))
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
        by_rules = sum(entry.by_rules for entry in entries.values())
        log.append(
            f"{len(unscheduled)} grupos sin programar: a {by_rules} se lo impiden sus reglas y a "
            f"{len(unscheduled) - by_rules} les faltó tiempo; sus motivos van en «unscheduled»."
        )

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
            intermediate_solutions=_complete_solutions(search, improvement),
        ),
        log=log,
    )


def run_status(has_movable: bool, placed_any: bool, unplaced: list[Unplaced], violations: bool) -> RunStatus:
    """`infeasible` solo si no quedó ningún grupo y a todos los que faltan se lo impiden sus reglas; si faltó tiempo
    para alguno, `partial`, igual que con grupos fuera o con duras; si no, `feasible`."""
    if has_movable and not placed_any and all(entry.by_rules for entry in unplaced):
        return RunStatus.INFEASIBLE
    if unplaced or violations:
        return RunStatus.PARTIAL

    return RunStatus.FEASIBLE


def _complete_solutions(search: Search, improvement: Improvement) -> int:
    """Las soluciones completas (todos los grupos programados) que encontró la corrida: la primera de `lns` más los
    vecindarios que la mejoraron; si `lns` no programó a todos y la reserva sí, solo esa; si no, ninguna."""
    if search.first_feasible_seconds is not None:
        return 1 + improvement.accepted

    return 0 if search.unplaced else 1
