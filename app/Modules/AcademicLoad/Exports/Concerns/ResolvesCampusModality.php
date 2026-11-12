<?php

namespace App\Modules\AcademicLoad\Exports\Concerns;

use App\Modules\Core\Models\Campus;

/**
 * La modalidad de lo que publica un campus, para preguntar el permiso de un exporte (AZ-S17): el exporte guarda solo
 * ids para la cola y hoy la carga hereda la modalidad de su campus.
 */
trait ResolvesCampusModality
{
    protected function modalityId(string $campusId): ?string
    {
        return Campus::withTrashed()->whereKey($campusId)->value('modality_id');
    }
}
