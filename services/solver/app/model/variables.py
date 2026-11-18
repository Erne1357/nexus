"""Las variables del modelo CP-SAT y su dominio pre-filtrado (tarea 7.4; docs/arquitectura/solver.md, «Modelo CP-SAT:
variables y restricciones duras»).

Dos familias de booleanas, enlazadas:

- `y[g, opción]`, el horario: una por opción de patrón factible de cada grupo movible. Una opción es un reparto de
  unidades en días (`pattern.tiers`, PS-01 a PS-04) con su bloque de inicio común (PS-02), así que fija las ventanas
  del grupo: una por día, `(día, bloque, unidades)`.
- `z[g, ventana, salón]`, el salón de cada ventana: el salón puede cambiar de un día a otro, como en la heurística, y en
  un día de 2 horas las dos van en el mismo salón (PS-05). Se enlaza con `sum_s z[g, w, s] = sum_{o ∋ w} y[g, o]`.

Solo se crea lo factible para las reglas activas (`hard_rules`): la rejilla del campus (HC-04), las horas exactas o
dentro de la tolerancia (HC-12), la indisponibilidad del docente (HC-09), la ocupación fija del docente (HC-01), de las
cohortes (HC-03) y de los salones (HC-02), la capacidad (HC-05), los bloqueos (HC-11), los préstamos (HC-10) y que
haya laboratorio para la práctica (HC-06). Los salones son siempre los del campus del grupo y, si los trae, los de
`candidate_room_ids`. Los grupos fijos y la ocupación de fuera de la corrida no tienen variables: son las constantes
que recortan el dominio.

La ocupación de cada docente, salón y cohorte por día queda en `Occupancy`: lo que usan las restricciones (HC-01 a
HC-03) y lo que usará el objetivo (7.6).
"""

from bisect import bisect_right
from collections import Counter, defaultdict
from collections.abc import Callable, Iterator
from dataclasses import dataclass, field

from ortools.sat.python import cp_model

from app.contract.input import GroupInput

from .data import ModelData, overlaps
from .pattern import DaySpread, Grid, tiers

#: La llave de una ventana dentro de su grupo: (día, bloque de inicio, unidades).
WindowKey = tuple[int, int, int]


@dataclass(eq=False)
class Window:
    """Las sesiones de un grupo en un día: `units` unidades seguidas desde `block`, de `start` a `end` en minutos."""

    group_id: str
    day: int
    block: int
    units: int
    start: int
    end: int
    #: Las opciones del grupo que pasan por esta ventana: su suma vale 1 si el grupo la usa.
    options: list["Option"] = field(default_factory=list)
    #: El salón de la ventana: salón -> literal.
    rooms: dict[str, cp_model.IntVar] = field(default_factory=dict)

    @property
    def literals(self) -> list[cp_model.IntVar]:
        """Los literales cuya suma dice si el grupo usa la ventana (a lo más uno es verdadero)."""
        return [option.literal for option in self.options]


@dataclass(eq=False)
class Option:
    """Una opción de horario del grupo: los días con sus unidades y el bloque de inicio."""

    group_id: str
    spread: DaySpread
    block: int
    #: El nivel del patrón (0 es el patrón; PS-04): el objetivo (7.6) puede preferir los primeros.
    tier: int
    #: Las unidades de práctica que pide la opción (HC-06; 0 si no lleva laboratorio).
    practice: int
    windows: tuple[Window, ...]
    literal: cp_model.IntVar


@dataclass
class GroupDomain:
    """El dominio de un grupo movible y lo que se descartó, por regla."""

    group: GroupInput
    grid: Grid
    #: Si su práctica pide laboratorio, taller o sala de cómputo (HC-06 activa y el campus usa salones).
    lab_practice: bool
    options: list[Option] = field(default_factory=list)
    windows: dict[WindowKey, Window] = field(default_factory=dict)
    #: Opciones descartadas por la primera regla que las impide.
    rejected_options: Counter[str] = field(default_factory=Counter)
    #: Pares de ventana y salón (o salones del campus, antes de ver ventanas) descartados por regla.
    rejected_rooms: Counter[str] = field(default_factory=Counter)

    @property
    def id(self) -> str:
        return self.group.id

    def main_reason(self) -> str | None:
        """La regla que más opciones descartó; útil para decir por qué un grupo no tiene ninguna."""
        if not self.rejected_options:
            return None

        return min(self.rejected_options, key=lambda rule: (-self.rejected_options[rule], rule))


@dataclass(frozen=True)
class Span:
    """Una ocupación posible: [start, end) en minutos, de un grupo, con los literales que la activan."""

    start: int
    end: int
    group_id: str
    literals: tuple[cp_model.IntVar, ...]


class Occupancy:
    """La ocupación posible de un recurso (docente, salón o cohorte) por día, en minutos.

    Dos ventanas se empalman si y solo si una contiene el inicio de la otra, así que basta mirar los inicios: en cada
    uno, los literales de las ventanas que lo cubren (`cliques`). Sirve para cualquier rejilla, también para un
    docente con grupos en dos campus con bloques distintos.
    """

    def __init__(self) -> None:
        self._spans: dict[tuple[str, int], list[Span]] = defaultdict(list)

    def add(self, key: str, day: int, span: Span) -> None:
        self._spans[(key, day)].append(span)

    def keys(self) -> list[tuple[str, int]]:
        return list(self._spans)

    def spans(self, key: str, day: int) -> list[Span]:
        return self._spans.get((key, day), [])

    def at(self, key: str, day: int, minute: int) -> list[cp_model.IntVar]:
        """Los literales de lo que ocupa el recurso en ese minuto; su suma vale 1 si está ocupado."""
        return [
            literal for span in self.spans(key, day) if span.start <= minute < span.end for literal in span.literals
        ]

    def cliques(self) -> Iterator[tuple[str, int, int, list[cp_model.IntVar]]]:
        """(recurso, día, minuto, literales) en cada inicio de ventana donde coinciden al menos dos grupos."""
        for (key, day), spans in self._spans.items():
            by_start: dict[int, list[Span]] = defaultdict(list)
            for span in spans:
                by_start[span.start].append(span)
            starts = sorted(by_start)
            longest = max(span.end - span.start for span in spans)
            for minute in starts:
                # Solo las que empiezan menos de una ventana antes pueden cubrir el minuto.
                earlier = starts[bisect_right(starts, minute - longest) : bisect_right(starts, minute)]
                covering = [span for start in earlier for span in by_start[start] if span.end > minute]
                if len({span.group_id for span in covering}) > 1:
                    yield key, day, minute, [literal for span in covering for literal in span.literals]


@dataclass
class Variables:
    """Las variables del modelo y la ocupación que resulta de ellas."""

    groups: dict[str, GroupDomain]
    teachers: Occupancy
    rooms: Occupancy
    cohorts: Occupancy

    @property
    def option_count(self) -> int:
        return sum(len(domain.options) for domain in self.groups.values())

    @property
    def room_count(self) -> int:
        return sum(len(window.rooms) for domain in self.groups.values() for window in domain.windows.values())

    def unplaceable(self) -> list[GroupDomain]:
        """Los grupos sin ninguna opción: ninguna solución los programa (gancho de la relajación, 7.7)."""
        return [domain for domain in self.groups.values() if not domain.options]


def build_variables(model: cp_model.CpModel, data: ModelData) -> Variables:
    """Crea `y` y `z` del dominio pre-filtrado y enlaza cada ventana con su salón."""
    variables = Variables(groups={}, teachers=Occupancy(), rooms=Occupancy(), cohorts=Occupancy())

    for group in data.movable:
        domain = _domain(model, data, group)
        variables.groups[group.id] = domain
        _register(domain, variables)

    return variables


def unit_counts(group: GroupInput, grid: Grid, data: ModelData) -> list[int]:
    """Las unidades semanales que puede llevar el grupo (HC-12, PS-06).

    Si la rejilla da sus horas exactas, esas. Si no (bloques de 50 o 90 min), las dos cantidades enteras más cercanas:
    con HC-12 activa, solo las que quedan dentro de la tolerancia de la entrada, así que con tolerancia 0 el grupo no
    tiene opciones, como en la heurística (supuesto por confirmar: la heurística no usa la tolerancia).
    """
    required = group.weekly_hours * 60
    unit = grid.unit_minutes
    if required % unit == 0:
        return [required // unit]

    counts = [count for count in (required // unit, required // unit + 1) if count >= 1]
    if data.active("HC-12"):
        counts = [count for count in counts if abs(count * unit - required) <= data.tolerance]

    return sorted(counts, key=lambda count: (abs(count * unit - required), count))


@dataclass(frozen=True)
class _WindowCheck:
    """Lo que el pre-filtro dice de una ventana: la regla que la impide (o `None`) y los salones que le sirven."""

    reason: str | None
    rooms: tuple[str, ...] = ()
    has_lab: bool = False


def _domain(model: cp_model.CpModel, data: ModelData, group: GroupInput) -> GroupDomain:
    grid = data.grids[group.campus_code]
    lab_practice = grid.uses_rooms and group.needs_lab and group.practice_hours > 0 and data.active("HC-06")
    domain = GroupDomain(group=group, grid=grid, lab_practice=lab_practice)
    rooms, static_rejected = _static_rooms(data, group) if grid.uses_rooms else ([], Counter())
    domain.rejected_rooms.update(static_rejected)
    checked: dict[WindowKey, _WindowCheck] = {}

    def check(key: WindowKey) -> _WindowCheck:
        if key not in checked:
            checked[key] = _check_window(data, group, grid, domain, rooms, static_rejected, key)

        return checked[key]

    for units in unit_counts(group, grid, data):
        practice = min(units, int(group.practice_hours * 60 / grid.unit_minutes + 0.5)) if lab_practice else 0
        for tier, spreads in enumerate(tiers(units, grid.days)):
            for spread in spreads:
                last = grid.blocks - max(count for _, count in spread) * grid.blocks_per_unit
                for block in range(last + 1):
                    reason = _option_reason(spread, block, practice, check)
                    if reason is None:
                        _add_option(model, grid, domain, spread, block, tier, practice)
                    else:
                        domain.rejected_options[reason] += 1

    for window in domain.windows.values():
        for room_id in check((window.day, window.block, window.units)).rooms:
            window.rooms[room_id] = model.new_bool_var("")
        if grid.uses_rooms:
            model.add(cp_model.LinearExpr.sum(list(window.rooms.values())) == cp_model.LinearExpr.sum(window.literals))

    return domain


def _option_reason(
    spread: DaySpread, block: int, practice: int, check: Callable[[WindowKey], _WindowCheck]
) -> str | None:
    """La regla que impide la opción: la de su primera ventana que no cabe, o HC-06 si sus ventanas con laboratorio no
    alcanzan para sus unidades de práctica."""
    lab_units = 0
    for day, count in spread:
        window = check((day, block, count))
        if window.reason is not None:
            return window.reason
        lab_units += count if window.has_lab else 0

    return "HC-06" if lab_units < practice else None


def _static_rooms(data: ModelData, group: GroupInput) -> tuple[list[str], Counter[str]]:
    """Los salones del campus del grupo (y de sus candidatos) que le sirven sin ver la hora, y los que no, por regla:
    capacidad (HC-05) y, si son de otro departamento, que haya algún préstamo para el suyo (HC-10)."""
    candidates = set(group.candidate_room_ids)
    rooms = []
    rejected: Counter[str] = Counter()
    for room_id in data.rooms_by_campus.get(group.campus_code, []):
        if candidates and room_id not in candidates:
            continue
        if data.active("HC-05") and data.rooms[room_id].capacity < group.capacity:
            rejected["HC-05"] += 1
        elif (
            data.active("HC-10")
            and data.needs_loan(room_id, group.department_id)
            and not data.has_loans(room_id, group.department_id)
        ):
            rejected["HC-10"] += 1
        else:
            rooms.append(room_id)

    return rooms, rejected


def _check_window(
    data: ModelData,
    group: GroupInput,
    grid: Grid,
    domain: GroupDomain,
    rooms: list[str],
    static_rejected: Counter[str],
    key: WindowKey,
) -> _WindowCheck:
    """El docente (HC-09, HC-01) y las cohortes (HC-03) libres de lo fijo en la ventana, y sus salones libres."""
    day, block, units = key
    start = grid.minute_of(block)
    end = start + units * grid.unit_minutes
    teacher = group.teacher_id

    if teacher is not None:
        if data.active("HC-09") and overlaps(data.teacher_unavailable.get(teacher, {}).get(day, []), start, end):
            return _WindowCheck("HC-09")
        if data.active("HC-01") and overlaps(data.teacher_fixed.get(teacher, {}).get(day, []), start, end):
            return _WindowCheck("HC-01")
    if data.active("HC-03") and any(
        overlaps(data.cohort_fixed.get(cohort, {}).get(day, []), start, end) for cohort in group.cohorts
    ):
        return _WindowCheck("HC-03")
    if not grid.uses_rooms:
        return _WindowCheck(None)

    eligible = []
    rejected: Counter[str] = Counter()
    for room_id in rooms:
        reason = _room_reason(data, group, room_id, day, start, end)
        if reason is None:
            eligible.append(room_id)
        else:
            rejected[reason] += 1
    domain.rejected_rooms.update(rejected)
    if eligible:
        return _WindowCheck(None, tuple(eligible), any(data.is_practice_space(room_id) for room_id in eligible))

    # Sin salón: la regla que más salones le quitó a la ventana, contando los que se quitaron sin ver la hora; sin
    # salones en el campus, HC-02, como la heurística.
    reasons = rejected + static_rejected

    return _WindowCheck(min(reasons, key=lambda rule: (-reasons[rule], rule)) if reasons else "HC-02")


def _room_reason(data: ModelData, group: GroupInput, room_id: str, day: int, start: int, end: int) -> str | None:
    if data.active("HC-11") and overlaps(data.room_blocks.get(room_id, {}).get(day, []), start, end):
        return "HC-11"
    if data.active("HC-02") and overlaps(data.room_fixed.get(room_id, {}).get(day, []), start, end):
        return "HC-02"
    if (
        data.active("HC-10")
        and data.needs_loan(room_id, group.department_id)
        and not data.loan_covers(room_id, group.department_id, day, start, end)
    ):
        return "HC-10"

    return None


def _add_option(
    model: cp_model.CpModel, grid: Grid, domain: GroupDomain, spread: DaySpread, block: int, tier: int, practice: int
) -> None:
    windows = []
    for day, count in spread:
        key = (day, block, count)
        window = domain.windows.get(key)
        if window is None:
            start = grid.minute_of(block)
            window = Window(domain.id, day, block, count, start, start + count * grid.unit_minutes)
            domain.windows[key] = window
        windows.append(window)

    option = Option(domain.id, spread, block, tier, practice, tuple(windows), model.new_bool_var(""))
    for window in windows:
        window.options.append(option)
    domain.options.append(option)


def _register(domain: GroupDomain, variables: Variables) -> None:
    """Pone las ventanas del grupo en la ocupación de su docente, sus cohortes y sus salones."""
    group = domain.group
    cohorts = sorted(set(group.cohorts))
    for window in domain.windows.values():
        span = Span(window.start, window.end, group.id, tuple(window.literals))
        if group.teacher_id is not None:
            variables.teachers.add(group.teacher_id, window.day, span)
        for cohort in cohorts:
            variables.cohorts.add(cohort, window.day, span)
        for room_id, literal in window.rooms.items():
            variables.rooms.add(room_id, window.day, Span(window.start, window.end, group.id, (literal,)))
