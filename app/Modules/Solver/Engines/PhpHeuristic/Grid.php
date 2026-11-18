<?php

namespace App\Modules\Solver\Engines\PhpHeuristic;

use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Solver\Contract\CampusGrid;

/**
 * La rejilla de un campus para la heurística (tarea 6.6): la jornada partida en bloques de `block_minutes` desde
 * `day_start` (HC-04), con el índice de cada bloque. Traduce un rango de minutos a los bloques que toca, para que los
 * índices de ocupación sean exactos aunque una ocupación de fuera de la corrida no esté alineada.
 *
 * La «hora» de una sesión (la unidad que coloca el motor) dura 60 minutos si el bloque cabe exacto en una hora (60, 30,
 * 20, 15 min…); si no (50 o 90 min), la unidad es un bloque, y las horas semanales tienen que ser un número entero de
 * bloques (supuesto por confirmar).
 */
final readonly class Grid
{
    /** El inicio de la jornada, en minutos desde la medianoche. */
    public int $start;

    /** Los bloques de la jornada: los que caben enteros entre `day_start` y `day_end`. */
    public int $blocks;

    /** Los minutos de una unidad (la «hora» de una sesión). */
    public int $unitMinutes;

    /** Los bloques de una unidad. */
    public int $blocksPerUnit;

    /**
     * @param  list<int>  $days
     */
    private function __construct(
        public string $code,
        public int $blockMinutes,
        public array $days,
        public bool $usesRooms,
        int $start,
        int $end,
    ) {
        $this->start = $start;
        $this->blocks = intdiv(max(0, $end - $start), $blockMinutes);
        $this->unitMinutes = 60 % $blockMinutes === 0 ? 60 : $blockMinutes;
        $this->blocksPerUnit = intdiv($this->unitMinutes, $blockMinutes);
    }

    public static function of(CampusGrid $grid): self
    {
        $days = array_values(array_unique($grid->days));
        sort($days);

        return new self($grid->code, $grid->blockMinutes, $days, $grid->usesRooms, Minutes::of($grid->dayStart), Minutes::of($grid->dayEnd));
    }

    /**
     * Las unidades de unas horas semanales, o null si la rejilla no las da exactas (HC-12).
     */
    public function units(int $weeklyHours): ?int
    {
        $minutes = $weeklyHours * 60;

        return $minutes % $this->unitMinutes === 0 ? intdiv($minutes, $this->unitMinutes) : null;
    }

    /**
     * Los bloques de la jornada que toca el rango [from, to) en minutos.
     *
     * @return list<int>
     */
    public function touched(int $from, int $to): array
    {
        if ($to <= $from || $this->blocks === 0) {
            return [];
        }

        $first = max(0, intdiv(max(0, $from - $this->start), $this->blockMinutes));
        $last = min($this->blocks - 1, intdiv($to - $this->start + $this->blockMinutes - 1, $this->blockMinutes) - 1);

        return $last < $first || $to <= $this->start ? [] : range($first, $last);
    }

    /**
     * Los bloques que la unión de los rangos cubre por completo, como llaves.
     *
     * @param  list<array{int, int}>  $ranges  [inicio, fin] en minutos
     * @return array<int, true>
     */
    public function covered(array $ranges): array
    {
        usort($ranges, fn (array $a, array $b): int => $a <=> $b);
        $merged = [];

        foreach ($ranges as [$from, $to]) {
            $last = count($merged) - 1;

            if ($last >= 0 && $from <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $to);
            } else {
                $merged[] = [$from, $to];
            }
        }

        $covered = [];

        foreach ($merged as [$from, $to]) {
            for ($block = 0; $block < $this->blocks; $block++) {
                $blockStart = $this->minuteOf($block);

                if ($blockStart >= $from && $blockStart + $this->blockMinutes <= $to) {
                    $covered[$block] = true;
                }
            }
        }

        return $covered;
    }

    /**
     * El minuto del día en que empieza el bloque.
     */
    public function minuteOf(int $block): int
    {
        return $this->start + $block * $this->blockMinutes;
    }
}
