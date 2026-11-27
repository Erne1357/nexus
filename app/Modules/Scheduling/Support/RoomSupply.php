<?php

namespace App\Modules\Scheduling\Support;

use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\State\StateRoom;
use App\Modules\Scheduling\Validation\State\StateRoomBlock;

/**
 * La oferta de salones de un campus en la semana, en memoria: qué salón le sirve a una sesión en un día y tramo sin
 * crear HC-02 (otro grupo lo ocupa), HC-05 (el grupo no cabe), HC-06 (una práctica con laboratorio fuera de un
 * espacio de práctica) ni HC-11 (bloqueado). Pura: no lee la base; quien la usa le pasa los salones y los bloqueos
 * vigentes del periodo y le dice qué tramos ya ocupan las sesiones con salón (`occupy`).
 *
 * La usan los pasos de la demo por puntos: el horario limpio (punto 5) aparta un salón para cada práctica con
 * laboratorio, así no la coloca donde ya no queda uno que le sirva, y el paso de salones (punto 7) asigna con ella un
 * salón a cada sesión presencial. Las horas van en minutos desde la medianoche, como en el validador (Minutes).
 *
 * Orden en que se elige: primero los preferidos que sirvan, en su orden (el salón por defecto de la materia, por
 * ejemplo); luego, si la sesión no pide espacio de práctica, las aulas y auditorios antes que los espacios de
 * práctica, para no gastarlos; luego los sin dueño o del departamento del grupo antes de los que piden préstamo
 * (HC-10); y al final el más justo en capacidad y, a igual capacidad, la clave menor. No es estado compartido: cada
 * paso crea la suya.
 */
final class RoomSupply
{
    /** @var array<string, StateRoom> los salones activos, por id */
    private array $rooms = [];

    /** @var list<StateRoom> los salones activos, del más chico al más grande y por clave */
    private array $bySize;

    /** @var array<string, array<int, list<array{int, int}>>> tramos ocupados por salón y día */
    private array $busy = [];

    /** @var array<string, array<int, list<array{int, int}>>> tramos bloqueados (HC-11) por salón y día */
    private array $blocked = [];

    /**
     * @param  iterable<StateRoom>  $rooms  los salones del campus; los inactivos no se ofrecen
     * @param  iterable<StateRoomBlock>  $blocks  los bloqueos vigentes del periodo
     */
    public function __construct(iterable $rooms, iterable $blocks = [])
    {
        foreach ($rooms as $room) {
            if ($room->isActive) {
                $this->rooms[$room->id] = $room;
            }
        }

        $bySize = array_values($this->rooms);
        usort($bySize, fn (StateRoom $a, StateRoom $b): int => [$a->capacity, $a->code] <=> [$b->capacity, $b->code]);
        $this->bySize = $bySize;

        foreach ($blocks as $block) {
            $this->blocked[$block->roomId][$block->day][] = [$block->start, $block->end];
        }
    }

    /**
     * La oferta de un campus leída de la foto del validador (ScheduleStateQuery): sus salones, sus bloqueos del
     * periodo y, como ocupados, los tramos de las sesiones de grupos activos que ya tienen salón ahí.
     */
    public static function fromState(ScheduleState $state, string $campusId): self
    {
        $rooms = array_values(array_filter($state->rooms(), fn (StateRoom $room): bool => $room->campusId === $campusId));
        $supply = new self($rooms, array_merge([], ...array_map(fn (StateRoom $room): array => $state->blocksOf($room->id), $rooms)));

        foreach ($rooms as $room) {
            foreach ($state->roomSessions($room->id) as $session) {
                $supply->occupy($room->id, $session->day, $session->start, $session->end);
            }
        }

        return $supply;
    }

    /**
     * El salón que le tocaría a la sesión, sin apartarlo; null si ninguno le sirve.
     *
     * @param  int  $capacity  la capacidad del grupo (HC-05)
     * @param  bool  $practiceSpace  si es la práctica de una materia con laboratorio (HC-06)
     * @param  string|null  $departmentId  el departamento del grupo: sus salones y los sin dueño van antes de los que piden préstamo
     * @param  list<string>  $preferred  ids de salón que van primero, en su orden, si sirven
     */
    public function find(int $day, int $start, int $end, int $capacity, bool $practiceSpace = false, ?string $departmentId = null, array $preferred = []): ?StateRoom
    {
        foreach ($preferred as $roomId) {
            $room = $this->rooms[$roomId] ?? null;

            if ($room !== null && $this->serves($room, $day, $start, $end, $capacity, $practiceSpace)) {
                return $room;
            }
        }

        $best = null;
        $bestRank = null;

        foreach ($this->bySize as $room) {
            if (! $this->serves($room, $day, $start, $end, $capacity, $practiceSpace)) {
                continue;
            }

            $rank = [
                ! $practiceSpace && $room->type->isPracticeSpace() ? 1 : 0,
                $departmentId !== null && $room->departmentId !== null && $room->departmentId !== $departmentId ? 1 : 0,
            ];

            if ($rank === [0, 0]) {
                return $room;
            }

            if ($bestRank === null || $rank < $bestRank) {
                [$best, $bestRank] = [$room, $rank];
            }
        }

        return $best;
    }

    /**
     * Si algún salón le sirve a la sesión en ese tramo.
     */
    public function fits(int $day, int $start, int $end, int $capacity, bool $practiceSpace = false): bool
    {
        return $this->find($day, $start, $end, $capacity, $practiceSpace) !== null;
    }

    /**
     * Aparta el salón que le toca a la sesión (find) y lo devuelve; null, sin apartar nada, si ninguno le sirve.
     *
     * @param  list<string>  $preferred
     */
    public function reserve(int $day, int $start, int $end, int $capacity, bool $practiceSpace = false, ?string $departmentId = null, array $preferred = []): ?StateRoom
    {
        $room = $this->find($day, $start, $end, $capacity, $practiceSpace, $departmentId, $preferred);

        if ($room !== null) {
            $this->occupy($room->id, $day, $start, $end);
        }

        return $room;
    }

    /**
     * Marca el salón como ocupado en ese tramo: una sesión que ya tiene salón, o uno apartado a mano.
     */
    public function occupy(string $roomId, int $day, int $start, int $end): void
    {
        $this->busy[$roomId][$day][] = [$start, $end];
    }

    /**
     * Libera un tramo que se apartó u ocupó con exactamente esas horas. Los bloqueos no se liberan.
     */
    public function release(string $roomId, int $day, int $start, int $end): void
    {
        $spans = $this->busy[$roomId][$day] ?? [];
        $index = array_search([$start, $end], $spans, true);

        if ($index !== false) {
            array_splice($spans, $index, 1);
            $this->busy[$roomId][$day] = $spans;
        }
    }

    private function serves(StateRoom $room, int $day, int $start, int $end, int $capacity, bool $practiceSpace): bool
    {
        if ($room->capacity < $capacity || ($practiceSpace && ! $room->type->isPracticeSpace())) {
            return false;
        }

        foreach ([...$this->busy[$room->id][$day] ?? [], ...$this->blocked[$room->id][$day] ?? []] as [$from, $to]) {
            if (Minutes::overlap($start, $end, $from, $to)) {
                return false;
            }
        }

        return true;
    }
}
