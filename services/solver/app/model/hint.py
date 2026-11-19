"""La pista de la entrada (`hint_sessions`; docs/arquitectura/solver.md, «Pista de la heurística»).

`CpSatEngine` en PHP manda como pista la salida de la heurística sobre los grupos movibles. Aquí se convierte a la
asignación del modelo, por grupo: la opción del patrón (reparto de días y bloque de inicio, con su nivel de PS-04) y
el salón de cada día. Los grupos con pista válida entran a la búsqueda ya programados (`search.lns` solo programa a los
demás) y, en cada vecindario donde se mueven, lo que llevan va como pista de CP-SAT en `y` y `z` (`search._add_hints`).

Una pista inválida no rompe la corrida: ese grupo se ignora, se programa como si no la trajera y el `log` lo dice. La
pista de un grupo es válida si:

- el grupo está en la entrada (el contrato ya rechaza otra cosa al validar la entrada; esto cubre entradas armadas sin
  validar, como las de los scripts) y es movible (la de un grupo fijo no hace nada, como dice el contrato, y no se
  reporta);
- cada sesión cae en la rejilla de su campus, alineada a sus bloques, sin repetir una hora;
- sus días y horas son una opción del patrón: unidades seguidas en cada día, el mismo bloque de inicio todos los días
  y un reparto de `pattern.tiers` para unas horas que da la rejilla (HC-12, PS-01 a PS-06);
- en un campus con salones, cada día va en un solo salón de su campus (y de sus candidatos, si los trae), las sesiones
  de práctica son tantas como sus unidades de práctica (como las escribe el modelo, `solution._practice_by_day`) y, si
  su práctica pide laboratorio, sus días en espacio de práctica las alcanzan (HC-06); en uno sin salones, sin salón y
  virtuales;
- el verificador (`verify.py`) no le encuentra duras con lo fijo y con los demás grupos con pista. De cada choque
  entre grupos con pista sale solo uno: el que tiene más duras y, si empatan, el que la pista trae después; así una
  sesión mala no deja fuera a todos los que toca. HC-08 no cuenta: es de la entrada, no del horario (PS-08).
"""

from collections import Counter, defaultdict
from dataclasses import dataclass, field

from app.contract.input import GroupInput, HintSession, SessionKind
from app.contract.output import ProposedSession

from .data import ModelData
from .pattern import DaySpread, Grid, clock, minutes, tiers
from .solution import current_slots
from .variables import practice_units, unit_counts
from .verify import hard_violations

#: Cuántos grupos ignorados se nombran en el `log`; los demás solo se cuentan.
LOGGED_IGNORED = 10


@dataclass(frozen=True)
class HintedGroup:
    """La pista válida de un grupo: sus sesiones (una por unidad, con `changed` contra `current_sessions`), su opción
    (reparto de días y bloque de inicio) y el nivel del patrón de esa opción."""

    sessions: list[ProposedSession]
    chosen: tuple[DaySpread, int]
    tier: int


@dataclass
class Hint:
    """Los grupos con pista válida y, de los demás, por qué se ignoró la suya."""

    groups: dict[str, HintedGroup] = field(default_factory=dict)
    ignored: dict[str, str] = field(default_factory=dict)

    def log(self) -> list[str]:
        """Las líneas del `log` de la salida: cuántos entraron con la pista y cuáles se ignoraron, con su motivo."""
        if not self.groups and not self.ignored:
            return []
        placed = len(self.groups)
        entered = "1 grupo entró" if placed == 1 else f"{placed} grupos entraron"
        lines = [f"Pista de la entrada: {entered} ya programado{'' if placed == 1 else 's'} con ella."]
        if self.ignored:
            named = list(self.ignored.items())[:LOGGED_IGNORED]
            listed = "; ".join(f"{group_id} ({reason})" for group_id, reason in named)
            rest = len(self.ignored) - len(named)
            tail = f" y {rest} más" if rest else ""
            which = (
                "1 grupo, que se programó"
                if len(self.ignored) == 1
                else f"{len(self.ignored)} grupos, que se programaron"
            )
            lines.append(f"Ignoró la pista de {which} sin ella: {listed}{tail}.")

        return lines


def read_hint(data: ModelData) -> Hint:
    """La pista de la entrada, grupo por grupo: la de cada grupo válido como `HintedGroup` y la de los demás, ignorada
    con su motivo."""
    hint = Hint()
    if not data.source.hint_sessions:
        return hint
    by_group: dict[str, list[HintSession]] = defaultdict(list)
    for session in data.source.hint_sessions:
        by_group[session.group_id].append(session)

    shaped: dict[str, HintedGroup] = {}
    for group_id, sessions in by_group.items():
        group = data.groups.get(group_id)
        if group is None:
            hint.ignored[group_id] = "el grupo no está en la entrada"
            continue
        if not group.movable:
            continue
        result = _shape(data, group, sessions)
        if isinstance(result, str):
            hint.ignored[group_id] = result
        else:
            shaped[group_id] = result

    proposed = [session for hinted in shaped.values() for session in hinted.sessions]
    violations = [
        (violation, [group_id for group_id in violation.group_ids if group_id in shaped])
        for violation in hard_violations(data.source, proposed)
        if violation.rule != "HC-08"
    ]
    counts = Counter(group_id for _, groups in violations for group_id in groups)
    order = {group_id: index for index, group_id in enumerate(shaped)}
    for violation, groups in violations:
        if not groups or any(group_id in hint.ignored for group_id in groups):
            continue
        # Quitar a uno de los grupos de la pista basta para que esta dura ya no esté.
        worst = max(groups, key=lambda group_id: (counts[group_id], order[group_id]))
        hint.ignored[worst] = f"{violation.rule}: {violation.message}"
    for group_id, hinted in shaped.items():
        if group_id not in hint.ignored:
            hint.groups[group_id] = hinted

    return hint


def _shape(data: ModelData, group: GroupInput, sessions: list[HintSession]) -> HintedGroup | str:
    """La pista de un grupo como opción del modelo, o el motivo por el que no es una."""
    grid = data.grids[group.campus_code]
    kinds: dict[tuple[int, int], SessionKind] = {}
    rooms_by_day: dict[int, set[str | None]] = defaultdict(set)
    for session in sessions:
        start, end = minutes(session.starts_at), minutes(session.ends_at)
        if (
            session.day not in grid.days
            or start < grid.start
            or end > grid.end
            or (start - grid.start) % grid.block_minutes
            or (end - start) % grid.unit_minutes
        ):
            return f"la sesión del día {session.day} de {session.starts_at} a {session.ends_at} no cae en la rejilla"
        for unit_start in range(start, end, grid.unit_minutes):
            if (session.day, unit_start) in kinds:
                return f"repite la hora del día {session.day} a las {clock(unit_start)}"
            kinds[(session.day, unit_start)] = session.kind
        rooms_by_day[session.day].add(session.room_id)

    option = _option(data, group, grid, sorted(kinds))
    if option is None:
        return "sus días y horas no son una opción del patrón de sesiones"
    spread, block, tier = option

    practice_count = sum(kind == SessionKind.PRACTICE for kind in kinds.values())
    problem = _room_problem(data, group, grid, spread, rooms_by_day, set(kinds.values()), practice_count)
    if problem is not None:
        return problem
    rooms = {day: next(iter(day_rooms)) for day, day_rooms in rooms_by_day.items()}
    current = current_slots(group)
    proposed = []
    for day, start in sorted(kinds):
        end = start + grid.unit_minutes
        kind = kinds[(day, start)]
        proposed.append(
            ProposedSession(
                group_id=group.id,
                day=day,
                starts_at=clock(start),
                ends_at=clock(end),
                room_id=rooms[day],
                kind=kind,
                changed=(day, start, end, rooms[day], kind) not in current,
            )
        )

    return HintedGroup(proposed, (spread, block), tier)


def _option(
    data: ModelData, group: GroupInput, grid: Grid, units: list[tuple[int, int]]
) -> tuple[DaySpread, int, int] | None:
    """El reparto de días, el bloque de inicio y el nivel del patrón de unas unidades (día, minuto de inicio) en orden:
    seguidas en cada día y desde el mismo bloque todos los días. Los niveles se numeran como en `variables._domain`:
    siguen de una cantidad de unidades a la otra (las horas exactas, o las dos más cercanas que da la rejilla)."""
    by_day: dict[int, list[int]] = defaultdict(list)
    for day, start in units:
        by_day[day].append(start)
    firsts = {starts[0] for starts in by_day.values()}
    if len(firsts) != 1:
        return None
    first = firsts.pop()
    for starts in by_day.values():
        if starts != list(range(first, first + len(starts) * grid.unit_minutes, grid.unit_minutes)):
            return None
    spread = tuple((day, len(by_day[day])) for day in sorted(by_day))
    block = (first - grid.start) // grid.block_minutes
    first_tier = 0
    for count in unit_counts(group, grid, data):
        unit_tiers = tiers(count, grid.days)
        if count == len(units):
            for tier, spreads in enumerate(unit_tiers, start=first_tier):
                if spread in spreads:
                    return spread, block, tier
        first_tier += len(unit_tiers)

    return None


def _room_problem(
    data: ModelData,
    group: GroupInput,
    grid: Grid,
    spread: DaySpread,
    rooms_by_day: dict[int, set[str | None]],
    kinds: set[SessionKind],
    practice_count: int,
) -> str | None:
    """Por qué los salones y tipos de la pista no le sirven al grupo, o `None`. Lo que depende de la hora (ocupación,
    bloqueos, préstamos) lo revisa el verificador."""
    if not grid.uses_rooms:
        if any(room_id is not None for day_rooms in rooms_by_day.values() for room_id in day_rooms):
            return "lleva salón y su campus no usa salones"
        if kinds != {SessionKind.VIRTUAL}:
            return "sus sesiones no son virtuales y su campus no usa salones"
        return None
    if SessionKind.VIRTUAL in kinds:
        return "lleva sesiones virtuales en un campus con salones"
    rooms: dict[int, str] = {}
    for day, day_rooms in rooms_by_day.items():
        if len(day_rooms) != 1:
            return f"el día {day} va en más de un salón"
        room_id = next(iter(day_rooms))
        if room_id is None:
            return f"el día {day} va sin salón"
        room = data.rooms.get(room_id)
        if room is None or room.campus_code != group.campus_code:
            return f"el salón {room_id} no es de su campus"
        if group.candidate_room_ids and room_id not in group.candidate_room_ids:
            return f"el salón {room_id} no está entre sus candidatos"
        rooms[day] = room_id
    total = sum(count for _, count in spread)
    practice = practice_units(group, grid, total) if group.practice_hours > 0 else 0
    if practice_count != practice:
        return f"lleva {practice_count} horas de práctica y el grupo tiene {practice}"
    if group.needs_lab and practice and data.active("HC-06"):
        in_labs = sum(count for day, count in spread if data.is_practice_space(rooms[day]))
        if in_labs < practice:
            return "HC-06: sus días en laboratorio, taller o sala de cómputo no alcanzan sus horas de práctica"

    return None
