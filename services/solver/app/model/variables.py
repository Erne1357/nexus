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

Para que quepa la carga de un departamento (`DomainOptions`, por omisión):

- **Salones en dos fases**: las ventanas de un bloque de los grupos del conjunto de salones de su campus (`RoomPool`)
  no llevan `z`; la fase A cuenta salones por bloque y la fase B los reparte (`rooms.py`). Las demás ventanas llevan su
  `z` explícito.
- **Niveles por necesidad**: cada grupo lleva solo las opciones del primer nivel del patrón donde alguna cabe (PS-04);
  si así no hay solución, se arma con todos los niveles.
"""

import time
from bisect import bisect_right
from collections import Counter, defaultdict
from collections.abc import Callable, Iterator, Mapping
from dataclasses import dataclass, field

from ortools.sat.python import cp_model

from app.contract.input import GroupInput

from .data import ModelData, overlaps
from .pattern import DaySpread, Grid, tiers
from .rooms import RoomPool

#: La llave de una ventana dentro de su grupo: (día, bloque de inicio, unidades).
WindowKey = tuple[int, int, int]

#: Un bloque de la rejilla de un campus: (campus, día, bloque).
BlockKey = tuple[str, int, int]


@dataclass(frozen=True)
class DomainOptions:
    """Cómo se arma el dominio."""

    #: Salones en dos fases: las ventanas de un bloque de los grupos de un `RoomPool` no llevan `z`.
    pool_rooms: bool = True
    #: Todos los niveles del patrón; si no, cada grupo hasta el primer nivel donde alguna opción cabe.
    all_tiers: bool = False
    #: Niveles de más, por grupo, después del primero donde alguna opción cabe (la segunda vuelta de los que no
    #: quedaron; PS-04).
    extra_tiers: Mapping[str, int] = field(default_factory=dict)
    #: Además, los niveles siguientes mientras sean de una hora por día (otros juegos de días, PS-04): no crean
    #: ventanas de más de un bloque, así que entran al conjunto de salones sin `z`.
    one_hour_tiers: bool = True
    #: Salones con `z` de cada ventana de más de un bloque de un grupo del conjunto: los de capacidad más ajustada
    #: (y los espacios de práctica, si los pide). `None` es todos. Lo usa la búsqueda por vecindarios para que un día
    #: de 2 horas no cueste 130 variables.
    long_window_rooms: int | None = None


@dataclass(eq=False, slots=True)
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
    #: El salón de la ventana: salón -> literal (vacío si la ventana es del conjunto de salones).
    rooms: dict[str, cp_model.IntVar] = field(default_factory=dict)
    #: Si su salón lo cuenta la fase A y lo reparte la fase B, sin `z`.
    pooled: bool = False
    #: En una ventana del conjunto de un grupo con laboratorio: verdadero si va en un espacio de práctica (HC-06).
    lab: cp_model.IntVar | None = None

    @property
    def literals(self) -> list[cp_model.IntVar]:
        """Los literales cuya suma dice si el grupo usa la ventana (a lo más uno es verdadero)."""
        return [option.literal for option in self.options]


@dataclass(eq=False, slots=True)
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
    #: Los lugares que pide a su salón (HC-05; 0 si la regla no está activa).
    threshold: int = 0
    #: El conjunto de salones de su campus, si sus ventanas de un bloque van sin `z`.
    pool: RoomPool | None = None
    #: Si quedaron niveles del patrón sin revisar porque uno anterior ya tenía opciones.
    truncated: bool = False
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


@dataclass(frozen=True, slots=True)
class Span:
    """Una ocupación posible: [start, end) en minutos, de un grupo, con los literales que la activan."""

    start: int
    end: int
    group_id: str
    literals: tuple[cp_model.IntVar, ...]


@dataclass(frozen=True, slots=True)
class _SpanIndex:
    """Las ocupaciones de un recurso y día por inicio, para no recorrerlas todas en cada minuto."""

    starts: list[int]
    by_start: dict[int, list[Span]]
    longest: int


class Occupancy:
    """La ocupación posible de un recurso (docente, salón o cohorte) por día, en minutos.

    Dos ventanas se empalman si y solo si una contiene el inicio de la otra, así que basta mirar los inicios: en cada
    uno, los literales de las ventanas que lo cubren (`cliques`). Sirve para cualquier rejilla, también para un
    docente con grupos en dos campus con bloques distintos.
    """

    def __init__(self) -> None:
        self._spans: dict[tuple[str, int], list[Span]] = defaultdict(list)
        self._indexes: dict[tuple[str, int], _SpanIndex] = {}

    def add(self, key: str, day: int, span: Span) -> None:
        self._spans[(key, day)].append(span)
        self._indexes.pop((key, day), None)

    def keys(self) -> list[tuple[str, int]]:
        return list(self._spans)

    def spans(self, key: str, day: int) -> list[Span]:
        return self._spans.get((key, day), [])

    def at(self, key: str, day: int, minute: int) -> list[cp_model.IntVar]:
        """Los literales de lo que ocupa el recurso en ese minuto; su suma vale 1 si está ocupado."""
        return [literal for span in self._covering(key, day, minute) for literal in span.literals]

    def cliques(self) -> Iterator[tuple[str, int, int, list[cp_model.IntVar]]]:
        """(recurso, día, minuto, literales) en cada inicio de ventana donde coinciden al menos dos grupos."""
        for key, day in self._spans:
            for minute in self._index(key, day).starts:
                covering = self._covering(key, day, minute)
                if len({span.group_id for span in covering}) > 1:
                    yield key, day, minute, [literal for span in covering for literal in span.literals]

    def _covering(self, key: str, day: int, minute: int) -> list[Span]:
        if (key, day) not in self._spans:
            return []
        index = self._index(key, day)
        # Solo las que empiezan menos de una ventana antes pueden cubrir el minuto.
        earlier = index.starts[bisect_right(index.starts, minute - index.longest) : bisect_right(index.starts, minute)]

        return [span for start in earlier for span in index.by_start[start] if span.end > minute]

    def _index(self, key: str, day: int) -> _SpanIndex:
        if (key, day) not in self._indexes:
            spans = self._spans[(key, day)]
            by_start: dict[int, list[Span]] = defaultdict(list)
            for span in spans:
                by_start[span.start].append(span)
            self._indexes[(key, day)] = _SpanIndex(sorted(by_start), by_start, max(s.end - s.start for s in spans))

        return self._indexes[(key, day)]


class ModelTooLarge(RuntimeError):
    """El modelo pasaría del límite de variables (`BuildBudget.max_variables`): la corrida falla antes de agotar la
    memoria del servicio, que comparte un proceso entre corridas."""


class BuildTimeout(RuntimeError):
    """Se acabó el plazo de la corrida mientras se armaba el modelo."""


@dataclass
class BuildBudget:
    """Los límites al armar: cuántas variables como mucho, hasta cuándo (`time.perf_counter()`) y cómo saber si
    cancelaron la corrida (`RunContext.check_cancelled`, que lanza `RunCancelled`). Se revisan cada
    `GROUPS_PER_TICK` grupos y antes de crear los salones de cada grupo."""

    max_variables: int | None = None
    deadline: float | None = None
    check_cancelled: Callable[[], None] | None = None

    def tick(self) -> None:
        if self.check_cancelled is not None:
            self.check_cancelled()
        if self.deadline is not None and time.perf_counter() > self.deadline:
            raise BuildTimeout("Se acabó el tiempo de la corrida mientras se armaba el modelo.")

    def reserve(self, created: int, more: int, done: int, total: int) -> None:
        """Lanza `ModelTooLarge` si crear `more` variables pasa del límite."""
        if self.max_variables is not None and created + more > self.max_variables:
            raise ModelTooLarge(
                f"El modelo pasa de {self.max_variables} variables: lleva {created} con {done} de {total} grupos "
                f"y el siguiente agrega {more}. Corre por partes (por carrera o por campus) o sube "
                "SOLVER_MAX_MODEL_VARIABLES si el servicio tiene memoria."
            )


#: Cada cuántos grupos se revisan la cancelación y el plazo al armar.
GROUPS_PER_TICK = 25


@dataclass
class Variables:
    """Las variables del modelo y la ocupación que resulta de ellas."""

    groups: dict[str, GroupDomain]
    teachers: Occupancy
    rooms: Occupancy
    cohorts: Occupancy
    options: DomainOptions = field(default_factory=DomainOptions)
    #: El conjunto de salones de cada campus que lo usa.
    pools: dict[str, RoomPool] = field(default_factory=dict)
    #: Las ventanas del conjunto en cada bloque.
    pooled: dict[BlockKey, list[Window]] = field(default_factory=dict)

    @property
    def option_count(self) -> int:
        return sum(len(domain.options) for domain in self.groups.values())

    @property
    def room_count(self) -> int:
        return sum(len(window.rooms) for domain in self.groups.values() for window in domain.windows.values())

    @property
    def lab_count(self) -> int:
        return sum(window.lab is not None for domain in self.groups.values() for window in domain.windows.values())

    @property
    def truncated(self) -> bool:
        """Si algún grupo quedó sin sus otros niveles: una segunda vuelta con todos podría tener solución."""
        return any(domain.truncated for domain in self.groups.values())

    def unplaceable(self) -> list[GroupDomain]:
        """Los grupos sin ninguna opción: ninguna solución los programa (gancho de la relajación, 7.7)."""
        return [domain for domain in self.groups.values() if not domain.options]


def build_variables(
    model: cp_model.CpModel,
    data: ModelData,
    options: DomainOptions | None = None,
    budget: BuildBudget | None = None,
) -> Variables:
    """Crea `y` y `z` del dominio pre-filtrado y enlaza cada ventana con su salón, dentro del presupuesto."""
    options = options or DomainOptions()
    budget = budget or BuildBudget()
    variables = Variables(groups={}, teachers=Occupancy(), rooms=Occupancy(), cohorts=Occupancy(), options=options)
    if options.pool_rooms:
        variables.pools = _pools(data)

    for index, group in enumerate(data.movable):
        if index % GROUPS_PER_TICK == 0:
            budget.tick()
        domain = _domain(model, data, group, variables, budget, index)
        variables.groups[group.id] = domain
        _register(domain, variables)

    return variables


def _pools(data: ModelData) -> dict[str, RoomPool]:
    """Un conjunto de salones por campus con salones: el del departamento con más grupos movibles en él."""
    departments: dict[str, Counter[str]] = defaultdict(Counter)
    for group in data.movable:
        if data.grids[group.campus_code].uses_rooms:
            departments[group.campus_code][group.department_id] += 1

    return {
        campus: RoomPool(data, campus, min(counts, key=lambda department: (-counts[department], department)))
        for campus, counts in departments.items()
    }


def _pool_of(data: ModelData, group: GroupInput, variables: Variables) -> RoomPool | None:
    """El conjunto de salones del grupo: el de su campus si es de su departamento (o si HC-10 no está activa, porque
    entonces el dueño no importa) y no trae `candidate_room_ids`."""
    pool = variables.pools.get(group.campus_code)
    if pool is None or group.candidate_room_ids:
        return None
    if data.active("HC-10") and pool.department_id != group.department_id:
        return None

    return pool


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


def practice_units(group: GroupInput, grid: Grid, units: int) -> int:
    """Las unidades de práctica de un grupo con `units` unidades semanales, redondeadas como en la heurística."""
    return min(units, int(group.practice_hours * 60 / grid.unit_minutes + 0.5))


@dataclass(frozen=True)
class _WindowCheck:
    """Lo que el pre-filtro dice de una ventana: la regla que la impide (o `None`) y los salones que le sirven."""

    reason: str | None
    rooms: tuple[str, ...] = ()
    has_lab: bool = False
    #: Si es una ventana de un bloque de un grupo con conjunto de salones: sin `z`.
    pooled: bool = False


def _domain(
    model: cp_model.CpModel, data: ModelData, group: GroupInput, variables: Variables, budget: BuildBudget, index: int
) -> GroupDomain:
    grid = data.grids[group.campus_code]
    lab_practice = grid.uses_rooms and group.needs_lab and group.practice_hours > 0 and data.active("HC-06")
    domain = GroupDomain(
        group=group,
        grid=grid,
        lab_practice=lab_practice,
        threshold=group.capacity if data.active("HC-05") else 0,
        pool=_pool_of(data, group, variables),
    )
    rooms, static_rejected = _static_rooms(data, group) if grid.uses_rooms else ([], Counter())
    domain.rejected_rooms.update(static_rejected)
    checked: dict[WindowKey, _WindowCheck] = {}

    def check(key: WindowKey) -> _WindowCheck:
        if key not in checked:
            checked[key] = _check_window(data, group, grid, domain, rooms, static_rejected, key, variables.options)

        return checked[key]

    # Los niveles siguen de una cantidad de unidades a la otra: el patrón de las horas más cercanas va antes que el de
    # las otras.
    first_tier = 0
    first_with_options: int | None = None
    extra = variables.options.extra_tiers.get(group.id, 0)
    for units in unit_counts(group, grid, data):
        practice = practice_units(group, grid, units) if lab_practice else 0
        unit_tiers = tiers(units, grid.days)
        for tier, spreads in enumerate(unit_tiers, start=first_tier):
            if first_with_options is None and domain.options:
                first_with_options = tier - 1
            one_hour = variables.options.one_hour_tiers and all(
                max(units for _, units in spread) == 1 for spread in spreads
            )
            if (
                not variables.options.all_tiers
                and first_with_options is not None
                and tier > first_with_options + extra
                and not one_hour
            ):
                domain.truncated = True
                break
            for spread in spreads:
                last = grid.blocks - max(count for _, count in spread) * grid.blocks_per_unit
                for block in range(last + 1):
                    reason = _option_reason(spread, block, practice, check)
                    if reason is None:
                        _add_option(model, grid, domain, spread, block, tier, practice)
                    else:
                        domain.rejected_options[reason] += 1
        first_tier += len(unit_tiers)

    rooms_needed = sum(len(check((window.day, window.block, window.units)).rooms) for window in domain.windows.values())
    budget.reserve(len(model.proto.variables), rooms_needed, index, len(data.movable))
    for window in domain.windows.values():
        window_check = check((window.day, window.block, window.units))
        window.pooled = window_check.pooled
        if window.pooled:
            if lab_practice and window_check.has_lab:
                window.lab = model.new_bool_var("")
                model.add(window.lab <= cp_model.LinearExpr.sum(window.literals))
            continue
        for room_id in window_check.rooms:
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
    options: DomainOptions,
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

    pool = domain.pool
    if pool is not None and units * grid.blocks_per_unit == 1 and pool.has_room(day, block, domain.threshold, False):
        has_lab = domain.lab_practice and pool.has_room(day, block, domain.threshold, True)
        return _WindowCheck(None, has_lab=has_lab, pooled=True)

    eligible = []
    rejected: Counter[str] = Counter()
    for room_id in rooms:
        reason = data.room_reason(room_id, group.department_id, day, start, end)
        if reason is None:
            eligible.append(room_id)
        else:
            rejected[reason] += 1
    domain.rejected_rooms.update(rejected)
    limit = options.long_window_rooms
    if eligible and pool is not None and limit is not None and len(eligible) > limit:
        by_fit = sorted(eligible, key=lambda room_id: (data.rooms[room_id].capacity, room_id))
        labs = [room_id for room_id in by_fit if data.is_practice_space(room_id)] if domain.lab_practice else []
        eligible = list(dict.fromkeys(by_fit[:limit] + labs[:limit]))
    if eligible:
        return _WindowCheck(None, tuple(eligible), any(data.is_practice_space(room_id) for room_id in eligible))

    # Sin salón: la regla que más salones le quitó a la ventana, contando los que se quitaron sin ver la hora. Si no se
    # quitó ninguno, el campus no tiene salones que el grupo pueda usar (o ninguno de sus candidatos es del campus):
    # HC-02, como `staticFailure` en la heurística, aunque la regla no esté activa.
    reasons = rejected + static_rejected

    return _WindowCheck(min(reasons, key=lambda rule: (-reasons[rule], rule)) if reasons else "HC-02")


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
        if window.pooled:
            variables.pooled.setdefault((group.campus_code, window.day, window.block), []).append(window)
        for room_id, literal in window.rooms.items():
            variables.rooms.add(room_id, window.day, Span(window.start, window.end, group.id, (literal,)))
