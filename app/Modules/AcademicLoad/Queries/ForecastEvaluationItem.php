<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\AcademicLoad\Enums\ForecastEvaluationStatus;

/**
 * Una evaluación en «Precisión de la proyección» (tarea 5.9c): lo que dicen su franja y su renglón en el drawer
 * «Evaluaciones». `scope` es «Presencial · Todos los campus»; `requestedAt`, «16/11/2026 a las 8:47»; `took`, lo que
 * tardó una lista, y `elapsed`, lo que lleva una que corre. `overall` y `careersTotal` son nulos mientras no se saben.
 */
final readonly class ForecastEvaluationItem
{
    public function __construct(
        public string $id,
        public string $termId,
        public string $term,
        public string $termCode,
        public string $modalityCode,
        public string $modality,
        public ?string $campusCode,
        public string $campus,
        public ForecastEvaluationStatus $status,
        public int $progress,
        public ?string $stage,
        public string $requestedAt,
        public string $requestedBy,
        public ?string $took,
        public ?string $elapsed,
        public ?ForecastMetrics $overall,
        public ?string $error,
        public int $careersDone,
        public ?int $careersTotal,
        public int $outOfScopeStudents,
    ) {}

    public function scope(): string
    {
        return "{$this->modality} · {$this->campus}";
    }

    /**
     * La primera oración del error, para el renglón de la lista; la franja lleva el error completo.
     */
    public function shortError(): ?string
    {
        if ($this->error === null) {
            return null;
        }

        $first = preg_split('/(?<=\.)\s/u', $this->error, 2)[0];

        return str_ends_with($first, '.') ? $first : "{$first}.";
    }
}
