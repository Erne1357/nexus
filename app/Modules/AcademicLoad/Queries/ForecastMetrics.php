<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\AcademicLoad\Enums\ForecastConfidence;
use App\Modules\AcademicLoad\Rules\ForecastAccuracy;

/**
 * Las métricas de ForecastAccuracy de un grupo de filas de una evaluación (DM-20 a DM-23), como las guarda
 * EvaluateForecast en `totals`: los flotantes con cuatro decimales y la confianza calculada sin redondear.
 * `bias` son alumnos por materia (Σ(proyectado − real) / n), no un porcentaje.
 */
final readonly class ForecastMetrics
{
    private const int DECIMALS = 4;

    public function __construct(
        public ?float $weightedError,
        public ?float $baselineError,
        public float $meanAbsoluteError,
        public float $bias,
        public ?float $withinShare,
        public ForecastConfidence $confidence,
        public int $rows,
    ) {}

    /**
     * Una entrada de `totals` (`overall`, una de `by_career`, `by_campus` o `by_department`).
     *
     * @param  array<string, mixed>  $entry
     */
    public static function fromTotals(array $entry): self
    {
        $float = fn (string $key): ?float => isset($entry[$key]) ? (float) $entry[$key] : null;

        return new self(
            weightedError: $float('weighted_error'),
            baselineError: $float('baseline_error'),
            meanAbsoluteError: (float) ($entry['mean_absolute_error'] ?? 0),
            bias: (float) ($entry['bias'] ?? 0),
            withinShare: $float('within_share'),
            confidence: ForecastConfidence::tryFrom((string) ($entry['confidence'] ?? '')) ?? ForecastConfidence::Unmeasured,
            rows: (int) ($entry['rows'] ?? 0),
        );
    }

    /**
     * Las métricas de unas filas que `totals` no trae ya calculadas (un campus y un departamento a la vez, o las materias
     * de una carrera).
     *
     * @param  list<array{projected: int, actual: int, baseline: int}>  $rows
     */
    public static function of(array $rows): self
    {
        $result = ForecastAccuracy::of($rows);
        $round = fn (?float $value): ?float => $value === null ? null : round($value, self::DECIMALS);

        return new self(
            weightedError: $round($result->weightedError),
            baselineError: $round($result->baselineError),
            meanAbsoluteError: round($result->meanAbsoluteError, self::DECIMALS),
            bias: round($result->bias, self::DECIMALS),
            withinShare: $round($result->withinShare),
            confidence: $result->confidence(),
            rows: count($rows),
        );
    }

    /**
     * Cuánto baja la fórmula el error de la regla de R1, en fracción (0.244 son 24.4 puntos); negativo si lo sube.
     */
    public function improvement(): ?float
    {
        return $this->weightedError === null || $this->baselineError === null ? null : $this->baselineError - $this->weightedError;
    }
}
