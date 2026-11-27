<?php

namespace App\Modules\Students\Queries;

/**
 * Los promedios de un alumno (AC-24), como los calcula StudentAveragesQuery: con dos decimales o null si no hay con qué promediar.
 */
final readonly class StudentAverages
{
    /**
     * @param  string|null  $general  promedio de las materias acreditadas
     * @param  string|null  $withFailed  promedio de todas las oportunidades calificadas, con las reprobadas
     * @param  string|null  $lastTerm  promedio del último periodo con calificaciones
     * @param  string|null  $lastTermCode  código SII de ese periodo (`20253`)
     */
    public function __construct(
        public ?string $general,
        public ?string $withFailed,
        public ?string $lastTerm,
        public ?string $lastTermCode,
    ) {}
}
