"""Los salones en dos fases (docs/arquitectura/solver.md, «Tamaño de la instancia real»).

**Fase A**, el horario. Las ventanas de un bloque de los grupos que comparten salones (`RoomPool`: los del departamento
del campus, sin `candidate_room_ids`) no llevan variable de salón. En cada bloque, el modelo cuenta cuántos de esos
grupos lo ocupan y no deja que pasen de los salones libres que les sirven (`add_pool_capacity` en `constraints.py`).

**Fase B**, los salones. Con el horario fijo, `assign_rooms` reparte los salones de cada bloque por emparejamiento.

**Por qué la fase B siempre tiene solución si la A la tiene.** En un bloque, a un grupo del conjunto le sirven los
salones libres de su campus que su departamento puede usar en ese bloque (sin bloqueo, sin ocupación fija, propios o
prestados), con al menos su capacidad (HC-05) y, si la ventana es de práctica de laboratorio, que sean espacio de
práctica (HC-06). Esos conjuntos son de dos formas, `S(c) = {cap ≥ c}` y `P(c) = {práctica, cap ≥ c}`, sobre los
mismos salones libres, porque todos los grupos del conjunto son del mismo departamento. Para cualquier conjunto de
sesiones con umbral de teoría más chico `a` y de práctica más chico `b`, sus salones posibles son exactamente la
unión `U(a, b)` de `S(a)` y `P(b)`, y las sesiones son a lo más las de teoría con umbral ≥ a más las de práctica con
umbral ≥ b. La fase A exige, para cada par de umbrales, que esas sesiones no pasen de `|U(a, b)|` menos los salones
de `U(a, b)` que ya tomaron las ventanas con salón explícito. Eso es la condición de Hall de cada conjunto de
sesiones, así que hay emparejamiento (teorema de Hall). Las ventanas de un bloque no se cruzan con las de otro
bloque, así que cada bloque se reparte por separado. Una ventana de más de un bloque (un día de 2 horas, o bloques
de 30 min) y los grupos de otro departamento o con candidatos llevan su `z` explícito, como antes, y entran a la
cuenta como salones ya tomados.
"""

from dataclasses import dataclass

from .data import ModelData
from .pattern import Grid

#: Un umbral que ninguna sesión pide: deja fuera ese tipo de la cuenta.
NONE = 1 << 30


class RoomAssignmentError(RuntimeError):
    """La fase B no encontró salón para una sesión. No debería pasar: la fase A lo garantiza (ver el docstring)."""


class RoomPool:
    """Los salones libres de un campus que puede usar el departamento del conjunto, por día y bloque."""

    def __init__(self, data: ModelData, campus_code: str, department_id: str) -> None:
        self.grid: Grid = data.grids[campus_code]
        self.department_id = department_id
        self._data = data
        rooms = data.rooms_by_campus.get(campus_code, [])
        self._free: dict[tuple[int, int], tuple[str, ...]] = {}
        for day in self.grid.days:
            for block in range(self.grid.blocks):
                start = self.grid.minute_of(block)
                end = start + self.grid.block_minutes
                free = [room for room in rooms if data.room_reason(room, department_id, day, start, end) is None]
                self._free[(day, block)] = tuple(sorted(free, key=lambda room: (data.rooms[room].capacity, room)))

    def free(self, day: int, block: int) -> tuple[str, ...]:
        """Los salones libres del bloque, del más chico al más grande."""
        return self._free.get((day, block), ())

    def fits(self, room_id: str, threshold: int, lab: bool) -> bool:
        return self._data.rooms[room_id].capacity >= threshold and (not lab or self.is_practice_space(room_id))

    def is_practice_space(self, room_id: str) -> bool:
        return self._data.is_practice_space(room_id)

    def has_room(self, day: int, block: int, threshold: int, lab: bool) -> bool:
        return any(self.fits(room, threshold, lab) for room in self.free(day, block))

    def union(self, day: int, block: int, theory: int, lab: int) -> list[str]:
        """`U(theory, lab)`: los salones libres con al menos `theory` lugares o de práctica con al menos `lab`."""
        return [room for room in self.free(day, block) if self.fits(room, theory, False) or self.fits(room, lab, True)]


@dataclass(frozen=True)
class PooledSession:
    """Una sesión de la fase B: la ventana de un bloque de un grupo, con su umbral y si va en laboratorio."""

    group_id: str
    day: int
    threshold: int
    lab: bool


def match(
    pool: RoomPool, day: int, block: int, sessions: list[PooledSession], taken: set[str], exclusive: bool
) -> dict:
    """El salón de cada sesión en un bloque: un emparejamiento completo (Kuhn) entre las sesiones y los salones libres
    que no tomó una ventana explícita. Primero las de laboratorio y las de más lugares; cada una prueba sus salones del
    más ajustado al más grande, las de teoría primero en aula. Sin HC-02, cada una toma el primero que le sirve."""
    ordered = sorted(sessions, key=lambda session: (not session.lab, -session.threshold, session.group_id))
    # Sin HC-02 los salones se comparten: los que tomaron las ventanas explícitas también sirven.
    candidates = [_candidates(pool, day, block, session, taken if exclusive else set()) for session in ordered]
    if not exclusive:
        return {session: options[0] for session, options in zip(ordered, candidates, strict=True) if options}

    owner: dict[str, int] = {}

    def augment(index: int, seen: set[str]) -> bool:
        for room in candidates[index]:
            if room in seen:
                continue
            seen.add(room)
            if room not in owner or augment(owner[room], seen):
                owner[room] = index
                return True
        return False

    for index, session in enumerate(ordered):
        if not augment(index, set()):
            raise RoomAssignmentError(
                f"Sin salón para {session.group_id} el día {day} en el bloque {block} de {pool.grid.code}."
            )

    return {ordered[index]: room for room, index in owner.items()}


def _candidates(pool: RoomPool, day: int, block: int, session: PooledSession, taken: set[str]) -> list[str]:
    rooms = [
        room for room in pool.free(day, block) if room not in taken and pool.fits(room, session.threshold, session.lab)
    ]
    if session.lab:
        return rooms

    # La teoría prefiere aula (PS-05); dentro de cada tipo, el más ajustado (ya vienen por capacidad).
    return sorted(rooms, key=pool.is_practice_space)
