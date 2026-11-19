<?php

namespace App\Modules\Solver\Engines\CpSat;

use RuntimeException;

/**
 * El servicio del motor no conoce la corrida (404, tarea 7.9). Sus corridas viven en memoria: casi siempre es que el
 * servicio se reinició a media corrida, y la salida se perdió.
 */
final class SolverRunNotFound extends RuntimeException
{
    public function __construct(public readonly string $serviceRunId)
    {
        parent::__construct('El servicio del motor ya no tiene esta corrida (pudo reiniciarse): vuelve a lanzarla.');
    }
}
