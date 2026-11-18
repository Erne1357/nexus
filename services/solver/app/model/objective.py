"""El objetivo del modelo CP-SAT: SC-01 a SC-09 como términos lineales, con sus pesos de la entrada (tarea 7.6;
docs/arquitectura/solver.md, «Modelo CP-SAT: objetivo»).

Cada regla cuenta lo mismo que su clase de `Scheduling/Rules/Soft` (y que `score.py`), en minutos de penalización: una
hora de hueco, de sesión o de empalme son 60, y un cambio de edificio o un traslado justo también son 60. Así el valor
de cada regla es 60 veces su penalización en la unidad de PHP, y el objetivo es exacto cuando todo cae en horas
enteras (rejillas de 60 min y lo fijo alineado, como en los fixtures y la carga real). Con bloques de 30 min o lo fijo
desalineado, un hueco de media hora vale 30 y no una hora: el objetivo es una aproximación, y el puntaje que se
reporta siempre sale de `score.py`.

Dónde va cada regla:

- **Por opción** (constantes de cada `y`): SC-02 (horas de más en un día), SC-04 y SC-08 (horas fuera de los bloques
  preferidos).
- **Huecos** de cada cohorte (SC-01) y de cada docente (SC-05) por día, por tramos de tiempo: un tramo libre con algo
  ocupado antes y algo después es hueco, salvo entre dos sesiones fijas que no son del alcance si ninguna sesión del
  modelo cae entre ellas (`ContractState` no cuenta esos huecos).
- **Empalmes** de pares de grupos de semestres seguidos (SC-07), por tramo de tiempo común a los dos.
- **Traslados** (SC-09): un fin de sesión en un campus y un inicio en otro a menos de `travel_minutes`, sin otra sesión
  del docente en medio.
- SC-03 y SC-06 dependen del salón: van en la fase B (`room_model.py`), con el horario ya fijo.

**Alcance.** Cuenta lo que toca a los grupos del alcance: los movibles, o los que diga quien arma el modelo. En un
vecindario de la búsqueda los demás programados entran fijos pero siguen en el alcance, así que sus huecos y empalmes
cuentan como en el problema completo.

**Preferencias del patrón** (PS-04 y PS-07): el nivel de la opción y las sesiones que cambian respecto a
`current_sessions`. No compiten con las reglas: las reglas van multiplicadas por `scale`, que es mayor que la suma de
todas las preferencias, así que solo desempatan (orden lexicográfico: reglas, nivel, hora actual).
"""

from collections import defaultdict
from collections.abc import Iterable
from dataclasses import dataclass, field
from itertools import pairwise

from ortools.sat.python import cp_model

from app.contract.input import SOFT_RULES

from .data import ModelData
from .pattern import minutes
from .score import Session, SoftScorer, hours, uncovered
from .variables import GroupDomain, Option, Span, Variables

#: Los minutos de penalización de una hora o de un evento (cambio de edificio, traslado).
HOUR = 60


@dataclass(frozen=True, slots=True)
class Fixed:
    """Una sesión que no se mueve en el modelo: de un grupo fijo (del alcance o no) o de fuera de la corrida."""

    start: int
    end: int
    group_id: str
    in_scope: bool
    campus: str


@dataclass
class Objective:
    """Los términos del objetivo, sin peso y en minutos, por regla; y las preferencias que desempatan."""

    weights: dict[str, int]
    rules: dict[str, list[tuple[int, cp_model.LinearExprT]]] = field(
        default_factory=lambda: {rule: [] for rule in SOFT_RULES}
    )
    preferences: list[tuple[int, cp_model.LinearExprT]] = field(default_factory=list)
    #: La cota superior de los minutos con peso (sin las preferencias): sirve para que quien maximiza los grupos
    #: programados le dé a cada grupo más que todo el puntaje.
    bound: int = 0
    #: Lo que vale un minuto de penalización frente a una unidad de preferencia.
    scale: int = 1

    def rule_expression(self, rule: str) -> cp_model.LinearExpr:
        return cp_model.LinearExpr.weighted_sum(
            [expr for _, expr in self.rules[rule]], [c for c, _ in self.rules[rule]]
        )

    def penalty(self) -> cp_model.LinearExpr:
        """Los minutos de penalización con peso de todas las reglas."""
        terms = [(self.weights[rule] * c, expr) for rule in SOFT_RULES for c, expr in self.rules[rule]]

        return cp_model.LinearExpr.weighted_sum([expr for _, expr in terms], [c for c, _ in terms])

    def preference(self) -> cp_model.LinearExpr:
        return cp_model.LinearExpr.weighted_sum(
            [expr for _, expr in self.preferences], [c for c, _ in self.preferences]
        )

    def expression(self) -> cp_model.LinearExpr:
        """Lo que se minimiza: las reglas por `scale` más las preferencias."""
        return self.scale * self.penalty() + self.preference()

    def by_rule(self, solver: cp_model.CpSolver) -> dict[str, int]:
        """El desglose con peso, en la unidad de PHP (minutos entre 60), de la solución del solver."""
        return {rule: self.weights[rule] * int(solver.value(self.rule_expression(rule))) // HOUR for rule in SOFT_RULES}

    def add(self, rule: str, coefficient: int, expression: cp_model.LinearExprT, bound: int) -> None:
        """Un término de la regla (minutos sin peso) con la cota de lo que puede valer."""
        if self.weights[rule] == 0 or coefficient == 0:
            return
        self.rules[rule].append((coefficient, expression))
        self.bound += self.weights[rule] * bound


def add_objective(
    model: cp_model.CpModel,
    variables: Variables,
    data: ModelData,
    scope: Iterable[str] | None = None,
    preferences: bool = True,
) -> Objective:
    """Arma los términos de SC-01, SC-02, SC-04, SC-05, SC-07, SC-08 y SC-09 (sin minimizar: quien resuelve decide
    cómo combinarlo con los grupos programados). Un peso cero apaga la regla: no crea nada."""
    scorer = SoftScorer(data.source)
    objective = Objective(weights=scorer.weights)
    in_scope = {group.id for group in data.movable} if scope is None else set(scope) & set(scorer.groups)
    builder = _Builder(model, variables, data, scorer, objective, in_scope)
    builder.option_terms()
    builder.gap_terms()
    builder.overlap_terms()
    builder.travel_terms()
    if preferences:
        builder.preference_terms()

    return objective


class _Builder:
    def __init__(
        self,
        model: cp_model.CpModel,
        variables: Variables,
        data: ModelData,
        scorer: SoftScorer,
        objective: Objective,
        in_scope: set[str],
    ) -> None:
        self.model = model
        self.variables = variables
        self.data = data
        self.scorer = scorer
        self.objective = objective
        self.in_scope = in_scope
        self.weights = objective.weights

    # --- Por opción: SC-02, SC-04, SC-08 -------------------------------------------------------------------------

    def option_terms(self) -> None:
        for domain in self.variables.groups.values():
            group = domain.group
            if group.id not in self.in_scope or not domain.options:
                continue
            unit = domain.grid.unit_minutes
            weekly = max(group.weekly_hours, group.practice_hours)
            share = max(1, -(-weekly // max(1, self.scorer.campus_days[group.campus_code])))
            blocks = self.scorer.preferred.get(group.teacher_id, []) if group.teacher_id is not None else []
            honorarios = group.teacher_id in self.scorer.honorarios
            spread_cost = [_spread_hours(option, unit, share) for option in domain.options]
            self._per_option("SC-02", domain, spread_cost)
            if blocks:
                outside = [_outside_hours(option, unit, blocks) for option in domain.options]
                self._per_option("SC-04", domain, outside)
                if honorarios:
                    self._per_option("SC-08", domain, outside)

    def _per_option(self, rule: str, domain: GroupDomain, hours_by_option: list[int]) -> None:
        if not any(hours_by_option):
            return
        literals = [option.literal for option in domain.options]
        self.objective.add(
            rule,
            1,
            cp_model.LinearExpr.weighted_sum(literals, [HOUR * h for h in hours_by_option]),
            HOUR * max(hours_by_option),
        )

    # --- Huecos: SC-01 por cohorte y SC-05 por docente ------------------------------------------------------------

    def gap_terms(self) -> None:
        if self.weights["SC-05"]:
            fixed = self._teacher_fixed()
            for teacher_id, day in list(self.variables.teachers.keys()):
                if not self._teacher_in_scope(teacher_id):
                    continue
                spans = self.variables.teachers.spans(teacher_id, day)
                self._gap(
                    "SC-05", f"{teacher_id}:{day}", spans, fixed.get((teacher_id, day), []), self.data.active("HC-01")
                )
        if self.weights["SC-01"]:
            fixed = self._cohort_fixed()
            touched = {key for group_id in self.in_scope for key in self.scorer.groups[group_id].cohorts}
            for key, day in list(self.variables.cohorts.keys()):
                if key not in touched:
                    continue
                spans = self.variables.cohorts.spans(key, day)
                self._gap("SC-01", f"{key}:{day}", spans, fixed.get((key, day), []), self.data.active("HC-03"))

    def _teacher_in_scope(self, teacher_id: str) -> bool:
        return any(group.teacher_id == teacher_id for group in self.scorer.groups.values() if group.id in self.in_scope)

    def _gap(self, rule: str, name: str, spans: list[Span], fixed: list[Fixed], exclusive: bool) -> None:
        """Los minutos de hueco del recurso en el día, por tramos: el día se parte en los tramos entre todos los
        inicios y fines posibles, y un tramo libre es hueco si hay algo ocupado antes y algo después.

        `antes[p] ≥ antes[p-1]`, `antes[p] ≥ ocupado[p-1]` (y al revés para `después`) y
        `hueco[p] ≥ antes[p] + después[p] - 1 - ocupado[p]`: al minimizar, cada booleana vale lo mínimo que permiten
        las sesiones que se usan, así que es exacto. Un tramo entre dos sesiones fijas que no son del alcance solo
        cuenta si alguna sesión del modelo cae entre ellas (`ContractState` no cuenta esos huecos)."""
        if not spans or (len(spans) == 1 and not fixed):
            return
        model = self.model
        pieces = _pieces([(span.start, span.end) for span in spans] + [(item.start, item.end) for item in fixed])
        occupied: list[cp_model.LinearExprT | int] = []
        for start, end in pieces:
            if any(item.start <= start and end <= item.end for item in fixed):
                occupied.append(1)
                continue
            literals = [
                literal for span in spans if span.start <= start and end <= span.end for literal in span.literals
            ]
            occupied.append(self._used(literals, exclusive) if literals else 0)
        count = len(pieces)
        before: list[cp_model.LinearExprT | int] = [0] * count
        after: list[cp_model.LinearExprT | int] = [0] * count
        for index in range(1, count):
            before[index] = self._either(before[index - 1], occupied[index - 1])
        for index in range(count - 2, -1, -1):
            after[index] = self._either(after[index + 1], occupied[index + 1])
        excluded = _fixed_gaps(fixed)
        terms: list[tuple[int, cp_model.LinearExprT]] = []
        touched: dict[tuple[int, int], cp_model.LinearExprT | int] = {}
        for index, (start, end) in enumerate(pieces):
            if isinstance(occupied[index], int) and occupied[index] == 1:
                continue
            if isinstance(before[index], int) and before[index] == 0:
                continue
            if isinstance(after[index], int) and after[index] == 0:
                continue
            bound = before[index] + after[index] - 1 - occupied[index]
            between = next(((a, b) for a, b in excluded if a <= start and end <= b), None)
            if between is not None:
                if between not in touched:
                    a, b = between
                    inside = [
                        literal for span in spans if a <= span.start and span.end <= b for literal in span.literals
                    ]
                    touched[between] = self._any(inside)
                if isinstance(touched[between], int):
                    continue
                bound = bound + touched[between] - 1
            gap = model.new_bool_var(f"{rule}:{name}:{start}")
            model.add(gap >= bound)
            terms.append((end - start, gap))
        if terms:
            minutes_total = sum(length for length, _ in terms)
            expression = cp_model.LinearExpr.weighted_sum([t for _, t in terms], [c for c, _ in terms])
            self.objective.add(rule, 1, expression, minutes_total)

    def _either(
        self, first: cp_model.LinearExprT | int, second: cp_model.LinearExprT | int
    ) -> cp_model.LinearExprT | int:
        """Una booleana que vale al menos lo que cada una de las dos (al minimizar, su «o»); con una constante, ella."""
        if (isinstance(first, int) and first == 1) or (isinstance(second, int) and second == 1):
            return 1
        if isinstance(first, int):
            return second
        if isinstance(second, int):
            return first
        either = self.model.new_bool_var("")
        self.model.add(either >= first)
        self.model.add(either >= second)

        return either

    def _any(self, literals: list) -> cp_model.LinearExprT | int:
        if not literals:
            return 0
        used = self.model.new_bool_var("")
        self.model.add(cp_model.LinearExpr.sum(literals) <= len(literals) * used)

        return used

    def _used(self, literals: list, exclusive: bool) -> cp_model.LinearExprT:
        """Si alguna de las ventanas se usa: su suma, si una regla dura ya impide que se usen dos; si no, una
        booleana."""
        if exclusive or len(literals) == 1:
            return cp_model.LinearExpr.sum(literals)
        used = self.model.new_bool_var("")
        self.model.add(cp_model.LinearExpr.sum(literals) <= len(literals) * used)

        return used

    def _teacher_fixed(self) -> dict[tuple[str, int], list[Fixed]]:
        """Por docente y día: las sesiones de sus grupos fijos y su ocupación de fuera de la corrida."""
        fixed: dict[tuple[str, int], list[Fixed]] = defaultdict(list)
        for group in self.scorer.groups.values():
            if group.movable or group.teacher_id is None:
                continue
            for session in self.scorer.fixed[group.id]:
                fixed[(group.teacher_id, session.day)].append(self._fixed(session))
        for teacher_id, sessions in self.scorer.busy.items():
            for session in sessions:
                fixed[(teacher_id, session.day)].append(self._fixed(session))

        return fixed

    def _cohort_fixed(self) -> dict[tuple[str, int], list[Fixed]]:
        fixed: dict[tuple[str, int], list[Fixed]] = defaultdict(list)
        for key, members in self.scorer.members.items():
            for group_id in members:
                if self.scorer.groups[group_id].movable:
                    continue
                for session in self.scorer.fixed[group_id]:
                    fixed[(key, session.day)].append(self._fixed(session))

        return fixed

    def _fixed(self, session: Session) -> Fixed:
        return Fixed(session.start, session.end, session.group_id, session.group_id in self.in_scope, session.campus)

    # --- Empalmes: SC-07 -------------------------------------------------------------------------------------------

    def overlap_terms(self) -> None:
        if not self.weights["SC-07"]:
            return
        for first, second in self._semester_pairs():
            mine, theirs = self._occupation(first), self._occupation(second)
            if mine is None and theirs is None:
                continue
            terms: list[tuple[int, cp_model.LinearExprT]] = []
            for day in set(self._days(first)) & set(self._days(second)):
                for start, end in _pieces(self._intervals(first, day) + self._intervals(second, day)):
                    a, b = self._covering(first, day, start, end), self._covering(second, day, start, end)
                    if a is None or b is None:
                        continue
                    if (isinstance(a, int) and a == 0) or (isinstance(b, int) and b == 0):
                        continue
                    if isinstance(a, int) or isinstance(b, int):
                        terms.append((end - start, a if isinstance(b, int) else b))
                        continue
                    both = self.model.new_bool_var("")
                    self.model.add(both >= a + b - 1)
                    terms.append((end - start, both))
            if terms:
                bound = sum(length for length, _ in terms)
                self.objective.add(
                    "SC-07", 1, cp_model.LinearExpr.weighted_sum([t for _, t in terms], [c for c, _ in terms]), bound
                )

    def _semester_pairs(self) -> list[tuple[str, str]]:
        """Los pares de `ConsecutiveSemesters`: un grupo del alcance con una cohorte `plan:` y los de la misma cohorte
        en el semestre de antes o de después, una vez por par."""
        pairs: dict[tuple[str, str], None] = {}
        for group_id in self.in_scope:
            for key in self.scorer.groups[group_id].cohorts:
                parts = key.split(":")
                if parts[0] != "plan" or len(parts) != 4:
                    continue
                _, plan, semester, campus = parts
                number = int(semester) if semester.lstrip("-").isdigit() else 0
                for other in (number - 1, number + 1):
                    for other_id in self.scorer.members.get(f"plan:{plan}:{other}:{campus}", []):
                        if other_id != group_id:
                            pairs[(min(group_id, other_id), max(group_id, other_id))] = None

        return list(pairs)

    def _occupation(self, group_id: str) -> GroupDomain | None:
        return self.variables.groups.get(group_id)

    def _days(self, group_id: str) -> list[int]:
        domain = self.variables.groups.get(group_id)
        if domain is not None:
            return [window.day for window in domain.windows.values()]
        if self.scorer.groups[group_id].movable:
            return []

        return [session.day for session in self.scorer.fixed[group_id]]

    def _intervals(self, group_id: str, day: int) -> list[tuple[int, int]]:
        domain = self.variables.groups.get(group_id)
        if domain is not None:
            return [(window.start, window.end) for window in domain.windows.values() if window.day == day]
        if self.scorer.groups[group_id].movable:
            return []

        return [(session.start, session.end) for session in self.scorer.fixed[group_id] if session.day == day]

    def _covering(self, group_id: str, day: int, start: int, end: int) -> cp_model.LinearExprT | int | None:
        """Si el grupo ocupa [start, end): una expresión de sus ventanas que lo cubren, 1 o 0 si es fijo, o `None` si un
        grupo movible fuera del modelo (sin programar) no ocupa nada."""
        domain = self.variables.groups.get(group_id)
        if domain is None:
            if self.scorer.groups[group_id].movable:
                return None
            return int(
                any(
                    session.day == day and session.start <= start and end <= session.end
                    for session in self.scorer.fixed[group_id]
                )
            )
        literals = [
            literal
            for window in domain.windows.values()
            if window.day == day and window.start <= start and end <= window.end
            for literal in window.literals
        ]

        return cp_model.LinearExpr.sum(literals) if literals else 0

    # --- Traslados: SC-09 ------------------------------------------------------------------------------------------

    def travel_terms(self) -> None:
        if not self.weights["SC-09"]:
            return
        travel = self.data.source.travel_minutes
        fixed = self._teacher_fixed()
        campus_of = {group_id: group.campus_code for group_id, group in self.scorer.groups.items()}
        for teacher_id, day in list(self.variables.teachers.keys()):
            if not self._teacher_in_scope(teacher_id):
                continue
            spans = self.variables.teachers.spans(teacher_id, day)
            constants = fixed.get((teacher_id, day), [])
            if len({campus_of[span.group_id] for span in spans} | {item.campus for item in constants}) < 2:
                continue
            ends: dict[tuple[str, int], list] = defaultdict(list)
            starts: dict[tuple[str, int], list] = defaultdict(list)
            for span in spans:
                ends[(campus_of[span.group_id], span.end)].extend(span.literals)
                starts[(campus_of[span.group_id], span.start)].extend(span.literals)
            fixed_ends = {(item.campus, item.end) for item in constants}
            fixed_starts = {(item.campus, item.start) for item in constants}
            for origin, end in set(ends) | fixed_ends:
                for target, start in set(starts) | fixed_starts:
                    if origin == target or not 0 <= start - end < travel:
                        continue
                    leaving = 1 if (origin, end) in fixed_ends else None
                    arriving = 1 if (target, start) in fixed_starts else None
                    if leaving and arriving:
                        continue
                    # Otra sesión fija que empieza entre las dos va antes en el orden de `CampusTravel`: no son
                    # seguidas.
                    if any(
                        end <= item.start <= start and (item.campus, item.start) != (target, start)
                        for item in constants
                    ):
                        continue
                    between = [
                        literal
                        for span in spans
                        if end <= span.start and span.end <= start
                        for literal in span.literals
                    ]
                    leave = leaving or cp_model.LinearExpr.sum(ends[(origin, end)])
                    arrive = arriving or cp_model.LinearExpr.sum(starts[(target, start)])
                    tight = self.model.new_bool_var("")
                    self.model.add(tight >= leave + arrive - 1 - cp_model.LinearExpr.sum(between))
                    self.objective.add("SC-09", HOUR, tight, HOUR)

    # --- Preferencias: PS-04 y PS-07 -------------------------------------------------------------------------------

    def preference_terms(self) -> None:
        total = 0
        for domain in self.variables.groups.values():
            if not domain.options:
                continue
            current = {
                (slot.day, minutes(slot.starts_at), minutes(slot.ends_at)) for slot in domain.group.current_sessions
            }
            unit = domain.grid.unit_minutes
            units = max(sum(count for _, count in option.spread) for option in domain.options)
            costs = [option.tier * (units + 1) + _changed(option, unit, current) for option in domain.options]
            if any(costs):
                self.objective.preferences.append(
                    (1, cp_model.LinearExpr.weighted_sum([option.literal for option in domain.options], costs))
                )
                total += max(costs)
        self.objective.scale = total + 1


def _spread_hours(option: Option, unit: int, share: int) -> int:
    """SC-02 de la opción: las horas de cada día por encima del reparto parejo."""
    return sum(max(0, hours(window.units * unit) - share) for window in option.windows)


def _outside_hours(option: Option, unit: int, blocks: list[tuple[int, int, int]]) -> int:
    """SC-04 de la opción: por cada sesión (una por unidad), sus horas fuera de los bloques preferidos."""
    return sum(
        hours(uncovered(window.day, window.start + i * unit, window.start + (i + 1) * unit, blocks))
        for window in option.windows
        for i in range(window.units)
    )


def _changed(option: Option, unit: int, current: set[tuple[int, int, int]]) -> int:
    """Las sesiones de la opción que no estaban en `current_sessions` (PS-07)."""
    return sum(
        (window.day, window.start + i * unit, window.start + (i + 1) * unit) not in current
        for window in option.windows
        for i in range(window.units)
    )


def _fixed_gaps(fixed: list[Fixed]) -> list[tuple[int, int]]:
    """Los huecos entre dos sesiones fijas seguidas que no son del alcance: `ContractState` no los cuenta si nada los
    parte."""
    ordered = sorted(fixed, key=lambda item: (item.start, item.end))
    found = []
    latest: Fixed | None = None
    for item in ordered:
        if latest is not None and item.start > latest.end and not latest.in_scope and not item.in_scope:
            found.append((latest.end, item.start))
        if latest is None or item.end > latest.end:
            latest = item

    return found


def _pieces(intervals: list[tuple[int, int]]) -> list[tuple[int, int]]:
    """Los tramos entre todos los inicios y fines de unos intervalos."""
    points = sorted({point for interval in intervals for point in interval})

    return list(pairwise(points))
