<?php

namespace App\Modules\AcademicLoad\Actions\Transitions;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Pasa la carga de asignación a ajustes: la llama Staffing al aprobar una solicitud de cambio (tarea 3.11,
 * decisión confirmada el 7 de noviembre de 2026). Su guarda exige que la carga, ya bloqueada, esté en asignación:
 * el diagrama también deja pasar a ajustes desde cerrada, aprobada o publicada, pero eso es reabrir (ReopenLoad), con
 * motivo. Quién puede lo decide la policy de Staffing.
 */
final class StartAdjusting
{
    public function __construct(private readonly TransitionLoad $transition) {}

    /**
     * @throws ValidationException
     */
    public function handle(AcademicLoad $load, User $actor, ?string $comment = null): AcademicLoad
    {
        return $this->transition->handle($load, LoadStatus::Adjusting, $actor, $comment, fn (AcademicLoad $load): array => $load->status === LoadStatus::Staffing
            ? []
            : ["La carga está en {$load->status->label()}: una solicitud de cambio solo la pasa a ajustes desde asignación."]);
    }
}
