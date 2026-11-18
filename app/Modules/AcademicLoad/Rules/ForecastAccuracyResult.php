<?php

namespace App\Modules\AcademicLoad\Rules;

use App\Modules\AcademicLoad\Enums\ForecastConfidence;

/**
 * Las métricas de una evaluación (ver ForecastAccuracy). `weightedError` y `baselineError` son null cuando lo real
 * suma cero («sin medir»); `withinShare`, cuando ninguna materia tuvo real mayor que cero.
 */
final readonly class ForecastAccuracyResult
{
    public function __construct(
        public ?float $weightedError,
        public ?float $baselineError,
        public float $meanAbsoluteError,
        public float $bias,
        public ?float $withinShare,
    ) {}

    /**
     * DM-22: sugerencia fuerte con error ponderado de 15 % o menos; referencia arriba; sin medir si no hay error.
     */
    public function confidence(): ForecastConfidence
    {
        return match (true) {
            $this->weightedError === null => ForecastConfidence::Unmeasured,
            $this->weightedError <= ForecastAccuracy::STRONG_THRESHOLD => ForecastConfidence::Strong,
            default => ForecastConfidence::Reference,
        };
    }
}
