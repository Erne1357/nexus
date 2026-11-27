<?php

namespace App\Modules\Staffing\Rules;

use App\Modules\AcademicLoad\Enums\LoadStatus;

/**
 * Los topes y estados de las solicitudes de cambio que usan las pantallas (reglas-de-negocio.md, "Solicitudes de
 * cambio"): se piden con la carga del departamento en Asignación o Ajustes, y lo que propone, el motivo y el
 * comentario del jefe llevan hasta 500 caracteres (supuesto por confirmar). Las Actions exigen lo demás.
 */
final class ChangeRequestRules
{
    public const int MAX_TEXT_LENGTH = 500;

    /**
     * Si con la carga en ese estado (nula si el departamento no tiene carga) se puede pedir un cambio.
     */
    public static function acceptsRequests(?LoadStatus $load): bool
    {
        return $load === LoadStatus::Staffing || $load === LoadStatus::Adjusting;
    }
}
