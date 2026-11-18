<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\AcademicLoad\Queries\ReanalysisRow;
use Carbon\CarbonImmutable;

/**
 * Lo que pinta «Re-análisis de la carga» de una carga en re-análisis (tarea 8.2, pantalla; opción B «Por materia»): los
 * bloques por materia y campus con los filtros aplicados, las cifras de los filtros de clase (sobre lo filtrado por
 * búsqueda, carrera y campus, sin la clase), las del filtro de campus (sobre lo demás, sin el campus), las carreras del
 * filtro de carrera, el umbral de RA-04 y lo que ya cambió contra la versión vigente (`changes`, nulo si no se pidió: el
 * coordinador no lo ve).
 */
final readonly class ReanalysisBoard
{
    /**
     * @param  list<ReanalysisBlock>  $blocks
     * @param  list<array{key: string, label: string, icon: string, tone: string, count: int}>  $chips
     * @param  array<string, int>  $campusCounts  por clave de campus
     * @param  list<array{id: string, name: string}>  $careers
     * @param  list<array{kind: string, icon: string, subject: string, subjectName: string, group: string, campus: ?string, title: string, detail: string}>|null  $changes
     */
    public function __construct(
        public array $blocks,
        public array $chips,
        public array $campusCounts,
        public int $campusTotal,
        public array $careers,
        public int $threshold,
        public int $rowCount,
        public ?int $snapshotVersion,
        public ?CarbonImmutable $forecastComputedAt,
        public ?array $changes,
    ) {}

    /**
     * Cuántos grupos cambiaron desde la versión vigente (RA-10), o null si no se calculó.
     */
    public function changedGroups(): ?int
    {
        return $this->changes === null ? null : count($this->changes);
    }

    /**
     * La diferencia en porcentaje de lo esperado (la base de RA-04, la misma de DemandSettings::isLargeDifference()):
     * positiva si falta oferta. Null si no se espera a nadie.
     */
    public static function percent(ReanalysisRow $row): ?int
    {
        return $row->expectedStudents === 0 ? null : (int) round($row->gap() * 100 / $row->expectedStudents);
    }

    /**
     * Lo que muestra la cifra principal de un renglón: el nuevo ingreso en primer semestre (RA-09), si no, los esperados.
     */
    public static function shownStudents(ReanalysisRow $row): int
    {
        return $row->balance === null && $row->isFirstSemester ? ($row->newStudents ?? $row->expectedStudents) : $row->expectedStudents;
    }

    /**
     * Cuántos campus tienen bloques a la vista.
     */
    public function campusesShown(): int
    {
        return count(array_unique(array_map(fn (ReanalysisBlock $block): string => $block->campus?->code ?? '', $this->blocks)));
    }
}
