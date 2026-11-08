<?php

namespace App\Modules\AcademicLoad\Actions\Transitions;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Regresa la carga de ajustes a asignación: la llama Staffing al aplicar la última solicitud de cambio aprobada
 * (tarea 3.11). Sin guardas propias; Scheduling la veta con LoadTransitioning si el horario tiene choques duros.
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
