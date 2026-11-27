<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;

/**
 * La modalidad y el periodo que ve una página de la consulta pública, con lo publicado en ellos (tarea 4.4; M1) y, si la
 * página filtra por campus (`?campus=`), ese campus: `schedule` ya trae solo sus grupos.
 */
final readonly class PublishedContext
{
    public function __construct(
        public Term $term,
        public Modality $modality,
        public ?Campus $campus,
        public PublishedSchedule $schedule,
    ) {}

    /**
     * Los parámetros de las rutas de la consulta en este contexto: el periodo, la modalidad (su clave en minúsculas) y,
     * si hay, el campus del filtro.
     *
     * @return array{periodo: string, modalidad: string, campus?: string}
     */
    public function routeParameters(): array
    {
        return [
            'periodo' => $this->term->code,
            'modalidad' => PublishedContextQuery::modalityKey($this->modality),
            ...($this->campus === null ? [] : ['campus' => $this->campus->code]),
        ];
    }
}
