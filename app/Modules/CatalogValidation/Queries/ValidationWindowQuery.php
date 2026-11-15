<?php

namespace App\Modules\CatalogValidation\Queries;

use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\StageWindow;
use App\Modules\Core\Queries\TermCalendarQuery;
use Carbon\CarbonImmutable;

/**
 * La ventana de la validación de catálogos de un periodo y su fecha límite (CV11, confirmado el 14 de noviembre de 2026): la
 * ventana **general** de la etapa «Validación de catálogos» del calendario (TermCalendarQuery), porque la validación es
 * de cada departamento para todo el Tec y no de una modalidad. Una modalidad con ventana propia no la cambia. Sin
 * ventana con fechas no hay fecha límite, y nada se rechaza por fecha (el calendario solo informa).
 */
final class ValidationWindowQuery
{
    public function __construct(private readonly TermCalendarQuery $calendar) {}

    public function window(Term $term): ?StageWindow
    {
        $window = collect($this->calendar->for($term, null))->first(fn (StageWindow $candidate): bool => $candidate->stage === Stage::CatalogValidation);

        return $window?->endsOn === null ? null : $window;
    }

    /**
     * El último día de la ventana general, o null si no tiene.
     */
    public function deadline(Term $term): ?CarbonImmutable
    {
        return $this->window($term)?->endsOn;
    }
}
