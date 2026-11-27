<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Staffing\Queries\TeacherSummaryQuery;
use Livewire\Component;

/**
 * El tablero «Resumen» del docente (tarea 1.85, IN-01 e IN-03; DESIGN.md, «Resumen del docente»): su ficha, cuatro cifras,
 * sus clases de hoy, lo que necesita su atención, su semana, su carga del periodo en planeación, su experiencia académica,
 * su disponibilidad y su acceso. Solo lectura: Core lo pinta con HomePanels para el puesto Docente sin importar Staffing.
 * Todo sale de TeacherSummaryQuery, que lee solo la ficha ligada al usuario de la sesión; el navegador no manda ningún id.
 */
new class extends Component
{
    /**
     * @return array<string, mixed>
     */
    public function with(Actor $context, TeacherSummaryQuery $summaries): array
    {
        return ['summary' => $summaries->for($context, now()->toImmutable()), 'user' => $context->user()];
    }
};
