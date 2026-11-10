<?php

namespace App\Modules\AcademicLoad\Actions\Transitions;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\LoadClosing;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Rules\Constraints\AgreementsAnswered;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cierra la carga de un departamento en un campus: de asignación a cerrada, la única forma de cerrar (tarea 4.1).
 * La guarda de AcademicLoad son los acuerdos sin respuesta (AgreementsAnswered); lo que falta a los grupos (HC-15) y
 * las solicitudes de cambio pendientes (P5) lo vetan Scheduling y Staffing con LoadTransitioning. En la misma
 * transacción guarda quién y cuándo la cerró (`closed_by`, `closed_at`: el último cierre; el historial completo está
 * en `load_transitions`) y avisa con LoadClosing, para que Scheduling confirme los grupos activos (P1, confirmado el 10 de
 * noviembre de 2026); si algo falla, la carga sigue en asignación. Quién puede cerrarla lo decide
 * AcademicLoadPolicy::close antes de llamarla.
 */
final class CloseLoad
{
    public function __construct(
        private readonly TransitionLoad $transition,
        private readonly AgreementsAnswered $agreements,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(AcademicLoad $load, User $actor, ?string $comment = null): AcademicLoad
    {
        return DB::transaction(function () use ($load, $actor, $comment): AcademicLoad {
            $load = $this->transition->handle($load, LoadStatus::Closed, $actor, $comment, $this->agreements->reasons(...));
            $load->update(['closed_by' => $actor->getKey(), 'closed_at' => now()]);

            LoadClosing::dispatch($load, $actor);

            return $load;
        });
    }
}
