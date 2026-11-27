<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\LoadStages;
use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Models\Term;

/**
 * Las etapas de la carga del usuario del contexto (MN-06, confirmado el 26 de noviembre de 2026: la decide el estado de la
 * carga de su departamento): los estados de las cargas de su periodo de carga (o del que se pida) que ve (`academic_load.load.view` sobre su
 * modalidad y su departamento), cada uno con sus etapas (LoadStatus::stages()), unidas en el orden del enum. Una consulta.
 */
final class LoadStagesQuery implements LoadStages
{
    /**
     * Si ve la carga de algún departamento (`academic_load.load.view`): el jefe y el JPD de un departamento académico; el
     * jefe de Servicios Escolares, no (AZ-02).
     */
    public function worksLoad(Actor $context): bool
    {
        return $context->allowsAny(AcademicLoadPermission::LoadView);
    }

    public function of(Actor $context, ?Term $term = null): array
    {
        $term ??= $context->loadTerm();

        if ($term === null) {
            return [];
        }

        $statuses = $context->constrain(AcademicLoad::query()->where('academic_loads.term_id', $term->getKey()), AcademicLoadPermission::LoadView, [
            'modality' => 'academic_loads.modality_id',
            'unit' => 'academic_loads.department_id',
        ])->distinct()->pluck('academic_loads.status')
            ->map(fn (LoadStatus|string $status): LoadStatus => $status instanceof LoadStatus ? $status : LoadStatus::from($status));

        return array_values(array_filter(
            Stage::cases(),
            fn (Stage $stage): bool => $statuses->contains(fn (LoadStatus $status): bool => in_array($stage, $status->stages(), true)),
        ));
    }
}
