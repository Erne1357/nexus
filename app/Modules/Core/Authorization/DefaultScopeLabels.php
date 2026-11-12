<?php

namespace App\Modules\Core\Authorization;

/**
 * Sin ningún módulo que nombre las carreras, no las nombra: el resumen cuenta «N carreras».
 */
final class DefaultScopeLabels implements ScopeLabels
{
    public function careers(array $careerIds): array
    {
        return [];
    }

    public function careerOptions(?string $campusId): array
    {
        return [];
    }

    public function careerGroups(?string $campusId): array
    {
        return [];
    }
}
