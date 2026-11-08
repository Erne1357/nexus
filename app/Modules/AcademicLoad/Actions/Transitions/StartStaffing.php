<?php

namespace App\Modules\AcademicLoad\Actions\Transitions;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Pasa la carga de propuesta a asignación docente. Desde este estado se pueden asignar docentes y toda la oferta
 * del departamento se vuelve visible para sus docentes (HC-17). No lleva guarda de módulos a la izquierda: que
 * los grupos y sesiones no tengan choques duros lo veta Scheduling, a la derecha, con LoadTransitioning. Quién
 * puede pasarla lo decide AcademicLoadPolicy::startStaffing antes de llamarla.
 */
final class StartStaffing
{
    public function __construct(private readonly TransitionLoad $transition) {}

    /**
     * @throws ValidationException
     */
    public function handle(AcademicLoad $load, User $actor, ?string $comment = null): AcademicLoad
    {
        return $this->transition->handle($load, LoadStatus::Staffing, $actor, $comment, fn (AcademicLoad $load): array => []);
    }
}
