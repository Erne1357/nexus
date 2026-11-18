<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use Carbon\CarbonImmutable;

/**
 * El estado de una carga en «Re-análisis de la carga» (tarea 8.2, pantalla): en qué parte del ciclo va (`stage`), la
 * línea del ciclo (Publicada vN → Calificaciones → Re-análisis → Cierre → Publicar vN+1), las tres condiciones de RA-02
 * con su estado, el avance de las calificaciones y quién inició el re-análisis y cuándo.
 *
 * - `stage`: `published` (se puede iniciar o espera a que se cumpla RA-02), `reanalysis`, `closing` (cerrada o aprobada
 *   después de un re-análisis: sigue en «Cierre de la carga») u `other` (cualquier otro estado: no hay ciclo).
 * - Un paso de la línea es `done`, `current`, `ready` (el siguiente, ya habilitado), `wait` (le falta algo que llega
 *   solo, como la proyección) o `next`. Una condición es `ok`, `wait` o `pending` (no se revisa hasta la anterior).
 */
final readonly class ReanalysisState
{
    /**
     * @param  'published'|'reanalysis'|'closing'|'other'  $stage
     * @param  list<array{key: string, label: string, date: ?string, detail: ?string, state: 'done'|'current'|'ready'|'wait'|'next'}>  $steps
     * @param  list<array{key: string, title: string, detail: string, state: 'ok'|'wait'|'pending'}>  $checks
     * @param  array<string, string>  $blockers  lo que falta para iniciar, por condición (ReanalysisAvailabilityQuery::blockers)
     * @param  array{graded: int, total: int, percent: int}  $progress
     */
    public function __construct(
        public AcademicLoad $load,
        public string $stage,
        public array $steps,
        public array $checks,
        public array $blockers,
        public array $progress,
        public string $gradesTerm,
        public ?CarbonImmutable $startedAt,
        public ?string $startedBy,
        public ?int $publishedGroups,
    ) {}

    /**
     * Si el re-análisis se puede iniciar ya (RA-02): publicada y sin nada que falte.
     */
    public function isAvailable(): bool
    {
        return $this->load->status === LoadStatus::Published && $this->blockers === [];
    }

    /**
     * El primer motivo por el que todavía no se puede iniciar, o null.
     */
    public function firstBlocker(): ?string
    {
        return array_values($this->blockers)[0] ?? null;
    }
}
