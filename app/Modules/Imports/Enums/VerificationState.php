<?php

namespace App\Modules\Imports\Enums;

/**
 * Cómo quedó la verificación de la última corrida real de imports:migrate-sii (`sii_verificacion._corrida.estado`).
 */
enum VerificationState: string
{
    /** Se comparó y todo cuadra. */
    case Verified = 'verificada';

    /** Se comparó y algo no cuadra: hay errores del paso `verificacion` en `_incidencias.csv`. */
    case WithDifferences = 'con diferencias';

    /** No se comparó (la razón queda en `_corrida.razon`); `materias` y `alumnos` quedan vacías. */
    case NotVerified = 'no verificada';
}
