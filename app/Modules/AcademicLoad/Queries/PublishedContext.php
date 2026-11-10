<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;

/**
 * El campus y el periodo que ve una página de la consulta pública, con lo publicado en ellos (tarea 4.4).
 */
final readonly class PublishedContext
{
    public function __construct(
        public Term $term,
        public Campus $campus,
        public PublishedSchedule $schedule,
    ) {}
}
