<?php

namespace App\Modules\Solver\Engines\CpSat;

use App\Modules\Solver\Contract\ContractException;
use RuntimeException;

/**
 * Una corrida que el servicio del motor atendió pero no terminó bien (tarea 7.9): falló con su motivo, no respondió a
 * tiempo, la canceló por su cuenta o devolvió una salida que no sirve. RunSolver la deja fallida con este mensaje.
 */
final class CpSatRunFailed extends RuntimeException
{
    public static function failed(?string $reason): self
    {
        $reason = trim((string) $reason);

        return new self($reason === ''
            ? 'El servicio del motor no pudo resolver la corrida.'
            : 'El servicio del motor no pudo resolver la corrida: '.rtrim($reason, '.').'.');
    }

    public static function timedOut(?SolverServiceTimedOut $previous = null): self
    {
        return new self('El servicio del motor no respondió a tiempo.', 0, $previous);
    }

    public static function cancelledByService(): self
    {
        return new self('El servicio del motor canceló la corrida por su cuenta.');
    }

    public static function unknownStatus(string $status): self
    {
        return new self("El servicio del motor respondió un estado desconocido («{$status}»).");
    }

    public static function invalidOutput(ContractException $exception): self
    {
        return new self('El servicio del motor devolvió una salida que no cumple el contrato: '.$exception->getMessage(), 0, $exception);
    }

    public static function foreignOutput(): self
    {
        return new self('El servicio del motor devolvió la salida de otra corrida.');
    }
}
