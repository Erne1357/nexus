<?php

namespace App\Modules\Academic\Authorization;

use App\Modules\Academic\Models\Career;
use App\Modules\Core\Authorization\ScopeLabels;

/**
 * Los códigos de carrera para el resumen de puestos de la credencial: una consulta por los ids pedidos.
 */
final class AcademicScopeLabels implements ScopeLabels
{
    public function careers(array $careerIds): array
    {
        if ($careerIds === []) {
            return [];
        }

        return Career::query()->whereKey($careerIds)->pluck('code', 'id')->all();
    }
}
