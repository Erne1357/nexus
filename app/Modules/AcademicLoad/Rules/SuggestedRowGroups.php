<?php

namespace App\Modules\AcademicLoad\Rules;

use App\Modules\Demand\Enums\ForecastMethod;
use App\Modules\Demand\Rules\SuggestedGroups;

/**
 * Los grupos que pide una fila de la propuesta al tomar lo sugerido en «Revisar proyección» (tarea 5.6, «Usar sugerido»), con
 * la regla de los paquetes de la 5.6b (la de RecalculatePackageGroups y SyncProposalSuggestions para una fila sin tocar): sin
 * paquetes, los grupos sugeridos de la proyección; con paquetes, los de paquetes más los sueltos sugeridos,
 * `ceil(max(0, esperados − nuevo ingreso del campus) / tamaño)` con 35, o 25 con laboratorio, restando el nuevo ingreso solo si
 * el método es `first_semester` (solo ahí los esperados lo incluyen). Nunca menos que los de paquetes. Pura.
 */
final class SuggestedRowGroups
{
    public static function for(int $suggestedGroups, int $expectedStudents, int $packageGroups, ?ForecastMethod $method, int $newStudents, bool $needsLab, SuggestedGroups $sizes): int
    {
        if ($packageGroups <= 0) {
            return $suggestedGroups;
        }

        $loose = $sizes->for(max(0, $expectedStudents - ($method === ForecastMethod::FirstSemester ? $newStudents : 0)), $needsLab);

        return $packageGroups + $loose;
    }
}
