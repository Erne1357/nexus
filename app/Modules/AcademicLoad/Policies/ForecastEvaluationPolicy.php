<?php

namespace App\Modules\AcademicLoad\Policies;

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;

/**
 * Quién ve el reporte de precisión de la proyección y quién lanza una evaluación (DM-24; C3, confirmado el 16 de
 * noviembre de 2026): Subdirección y el admin. Son permisos globales, sin modalidad ni unidad.
 */
final class ForecastEvaluationPolicy
{
    use AuthorizesActor;

    public function viewAny(User $user): bool
    {
        return $this->actor($user)->allowsAny(AcademicLoadPermission::ForecastEvaluationView);
    }

    public function create(User $user): bool
    {
        return $this->actor($user)->allowsAny(AcademicLoadPermission::ForecastEvaluationRun);
    }
}
