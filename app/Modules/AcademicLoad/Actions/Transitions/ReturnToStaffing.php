<?php

namespace App\Modules\AcademicLoad\Actions\Transitions;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Regresa la carga de ajustes a asignación: la llama Staffing al aplicar la última solicitud de cambio aprobada
 * (tarea 3.11) y la llama el jefe con «Volver a asignación» (tarea 4.2). Sin guardas propias; la vetan con
 * LoadTransitioning Scheduling, si el horario tiene choques duros, y Staffing, si quedan solicitudes de cambio aprobadas
 * sin aplicar. La pantalla del jefe la autoriza con AcademicLoadPolicy::startStaffing (`academic_load.load.start_staffing`,
 * el jefe del departamento): es quien devuelve la carga a asignación en su campus y departamento, y no hace falta otro
 * permiso (supuesto por confirmar). Quién puede llamarla lo decide la policy antes.
 */
final class ReturnToStaffing
{
    public function __construct(private readonly TransitionLoad $transition) {}

    /**
     * @throws ValidationException
     */
    public function handle(AcademicLoad $load, User $actor, ?string $comment = null): AcademicLoad
    {
        return $this->transition->handle($load, LoadStatus::Staffing, $actor, $comment, fn (): array => []);
    }
}
