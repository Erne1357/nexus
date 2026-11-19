"""La rejilla de un campus y el patrón de sesiones, los mismos que usa la heurística en PHP.

Espejo de `Engines/PhpHeuristic/Grid.php` y `SessionPattern.php` (reglas-de-negocio.md, «Patrón de sesiones», PS-01 a
PS-06): sesiones de una hora, una por día en días distintos (PS-01) y a la misma hora todos los días (PS-02). Las
opciones van por niveles: el patrón, los otros juegos con la misma cantidad de días (PS-04) y, después, menos días con
el reparto más parejo, hasta 2 horas por día.
"""

from dataclasses import dataclass
from itertools import combinations

from app.contract.input import CampusGrid

#: El sábado: un juego de días sin sábado va antes que uno con sábado.
SATURDAY = 6

#: Una opción de días: (día, unidades) en orden de día.
DaySpread = tuple[tuple[int, int], ...]


def minutes(clock: str) -> int:
    """`"07:30"` -> 450."""
    return int(clock[:2]) * 60 + int(clock[3:])


def clock(value: int) -> str:
    """450 -> `"07:30"`."""
    return f"{value // 60:02d}:{value % 60:02d}"


@dataclass(frozen=True)
class Grid:
    """La jornada de un campus partida en bloques de `block_minutes` desde `day_start` (HC-04).

    La «hora» de una sesión (la unidad que coloca el motor) dura 60 minutos si el bloque cabe exacto en una hora (60,
    30, 20, 15 min…); si no (50 o 90 min), la unidad es un bloque (PS-06).
    """

    code: str
    block_minutes: int
    start: int
    end: int
    days: tuple[int, ...]
    uses_rooms: bool

    @classmethod
    def of(cls, campus: CampusGrid) -> "Grid":
        return cls(
            code=campus.code,
            block_minutes=campus.block_minutes,
            start=minutes(campus.day_start),
            end=minutes(campus.day_end),
            days=tuple(sorted(set(campus.days))),
            uses_rooms=campus.uses_rooms,
        )

    @property
    def blocks(self) -> int:
        """Los bloques de la jornada: los que caben enteros entre `day_start` y `day_end`."""
        return max(0, self.end - self.start) // self.block_minutes

    @property
    def unit_minutes(self) -> int:
        return 60 if 60 % self.block_minutes == 0 else self.block_minutes

    @property
    def blocks_per_unit(self) -> int:
        return self.unit_minutes // self.block_minutes

    def minute_of(self, block: int) -> int:
        """El minuto del día en que empieza el bloque."""
        return self.start + block * self.block_minutes


def tiers(units: int, campus_days: tuple[int, ...] | list[int]) -> list[list[DaySpread]]:
    """Los niveles de opciones para `units` sesiones en los días del campus, como `SessionPattern::tiers`.

    El primer nivel es el patrón (los primeros días del campus, una unidad por día); el segundo, los otros juegos con la
    misma cantidad de días; después, un nivel por cada cantidad menor de días, con el reparto más parejo.
    """
    days = sorted(set(campus_days))
    count = len(days)
    if units < 1 or count == 0:
        return []

    most = min(units, count)
    per_day = max(2, -(-units // count))
    fewest = -(-units // per_day)
    result: list[list[DaySpread]] = []

    for k in range(most, fewest - 1, -1):
        options = [
            [tuple(zip(subset, spread, strict=True)) for spread in _spreads(units, k)] for subset in _subsets(days, k)
        ]
        if k == most:
            result.append(options[0])
            rest = [option for group in options[1:] for option in group]
            if rest:
                result.append(rest)
            continue
        result.append([option for group in options for option in group])

    return result


def _subsets(days: list[int], k: int) -> list[tuple[int, ...]]:
    """Los juegos de `k` días, el más parecido a los primeros `k` días del campus primero y los que llevan sábado al
    final."""
    first = set(days[:k])

    return sorted(combinations(days, k), key=lambda subset: (len(set(subset) - first), SATURDAY in subset, subset))


def _spreads(units: int, k: int) -> list[list[int]]:
    """Los repartos de `units` en `k` días lo más parejos posible; las unidades de sobra, primero en los días más
    tempranos."""
    base, extra = divmod(units, k)
    spreads = []
    for positions in combinations(range(k), extra):
        spread = [base] * k
        for position in positions:
            spread[position] += 1
        spreads.append(spread)

    return spreads
