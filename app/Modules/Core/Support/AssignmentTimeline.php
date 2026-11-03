<?php

namespace App\Modules\Core\Support;

use App\Modules\Core\Enums\AssignmentStatus;
use Carbon\CarbonImmutable;

/**
 * El acomodo de la «Línea de vigencias» de la ficha de una persona (DESIGN.md, «Personas y puestos»): del 1 de enero
 * del primer año (el de hoy, si es menor) al 31 de diciembre del último fin o de hoy, con una barra por
 * nombramiento. Las posiciones son porcentajes del rango y el último día cuenta completo; un nombramiento sin fin
 * llega al borde y se marca `open`. Solo calcula: no lee la base ni guarda estado.
 */
final readonly class AssignmentTimeline
{
    /**
     * @param  list<array{label: string, left: float}>  $years  el rótulo y la posición de cada año del rango
     * @param  float|null  $today  la marca de hoy, como porcentaje del rango; null si no hay rango
     * @param  list<array{label: string, status: AssignmentStatus, left: float, width: float, open: bool}>  $rows
     */
    private function __construct(
        public array $years,
        public ?float $today,
        public array $rows,
    ) {}

    /**
     * @param  list<array{label: string, from: CarbonImmutable, to: ?CarbonImmutable, status: AssignmentStatus}>  $rows
     */
    public static function build(array $rows, CarbonImmutable $today): self
    {
        if ($rows === []) {
            return new self([], null, []);
        }

        $firstYear = min($today->year, ...array_map(fn (array $row): int => $row['from']->year, $rows));
        $lastYear = max($today->year, ...array_map(fn (array $row): int => ($row['to'] ?? $row['from'])->year, $rows));
        $start = CarbonImmutable::create($firstYear, 1, 1)->startOfDay();
        $days = (int) $start->diffInDays(CarbonImmutable::create($lastYear, 12, 31)->startOfDay()) + 1;

        $years = [];

        for ($year = $firstYear; $year <= $lastYear; $year++) {
            $years[] = ['label' => (string) $year, 'left' => (int) $start->diffInDays(CarbonImmutable::create($year, 1, 1)->startOfDay()) / $days * 100.0];
        }

        $bars = array_map(function (array $row) use ($start, $days): array {
            $left = (int) $start->diffInDays($row['from']->startOfDay());
            $open = $row['to'] === null;
            $span = $open ? $days - $left : (int) $row['from']->startOfDay()->diffInDays($row['to']->startOfDay()) + 1;

            return [
                'label' => $row['label'],
                'status' => $row['status'],
                'left' => $left / $days * 100.0,
                'width' => $span / $days * 100.0,
                'open' => $open,
            ];
        }, $rows);

        return new self($years, ((int) $start->diffInDays($today->startOfDay()) + 0.5) / $days * 100, $bars);
    }

    public function hasRange(): bool
    {
        return $this->rows !== [];
    }
}
