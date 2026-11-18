<?php

namespace App\Modules\Solver\Engines\CpSat;

use RuntimeException;
use Throwable;

/**
 * Las consultas al servicio del motor se vencieron (tarea 7.9): el servicio está pero no contesta en `poll_timeout`
 * (todos los intentos), o la siguiente consulta ya no terminaría antes del límite de la corrida. CpSatEngine cancela
 * la corrida en el servicio y la deja fallida con «El servicio del motor no respondió a tiempo.».
 */
final class SolverServiceTimedOut extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('El servicio del motor no respondió a tiempo.', 0, $previous);
    }
}
