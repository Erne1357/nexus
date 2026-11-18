<?php

namespace App\Modules\Solver\Engines\CpSat;

use RuntimeException;
use Throwable;

/**
 * El servicio del motor no atendió la petición (tarea 7.9): no se pudo conectar, respondió 5xx, está lleno (429),
 * rechazó el token (401) o respondió algo que no es de su API. El mensaje es para la persona que lanzó la corrida
 * (SV-08); el detalle técnico va en la excepción previa.
 */
final class SolverServiceUnavailable extends RuntimeException
{
    public static function unreachable(?Throwable $previous = null): self
    {
        return new self('El servicio del motor no está disponible.', 0, $previous);
    }

    public static function busy(): self
    {
        return new self('El servicio del motor está ocupado con otras corridas: vuelve a lanzarla en unos minutos.');
    }

    public static function tokenRejected(): self
    {
        return new self('El servicio del motor no está disponible: rechazó el token de Nexus (SOLVER_TOKEN).');
    }

    public static function unexpected(int $status): self
    {
        return new self("El servicio del motor no está disponible: respondió algo inesperado (HTTP {$status}).");
    }
}
