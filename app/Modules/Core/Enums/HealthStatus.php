<?php

namespace App\Modules\Core\Enums;

/**
 * El estado de un chequeo o de todo `/health`: `ok`, `degraded` (sirve, pero algo no crítico falla), `draining` (el
 * contenedor se está drenando) o `fail` (un chequeo crítico falla).
 */
enum HealthStatus: string
{
    case Ok = 'ok';
    case Degraded = 'degraded';
    case Draining = 'draining';
    case Fail = 'fail';
}
