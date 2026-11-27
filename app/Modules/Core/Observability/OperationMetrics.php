<?php

namespace App\Modules\Core\Observability;

/**
 * Acumulador de la petición (o del job) en curso: tiempo total, consultas y llamadas HTTP salientes.
 *
 * Se registra `scoped` en CoreServiceProvider: Octane lo suelta entre peticiones y nada cruza de una a otra. Laravel
 * resuelve otra instancia de los middleware para `terminate()`, por eso la duración vive aquí y no en ellos.
 */
final class OperationMetrics
{
    private int $startedAt;

    private ?float $stoppedMs = null;

    private float $dbMs = 0.0;

    private int $dbQueries = 0;

    private float $externalMs = 0.0;

    public function __construct()
    {
        $this->startedAt = hrtime(true);
    }

    public function start(): void
    {
        $this->startedAt = hrtime(true);
        $this->stoppedMs = null;
        $this->dbMs = 0.0;
        $this->dbQueries = 0;
        $this->externalMs = 0.0;
    }

    /** Congela la duración: `terminate()` corre después de enviar la respuesta y no debe contar el envío. */
    public function stop(): void
    {
        $this->stoppedMs = $this->elapsedMs();
    }

    public function addQuery(float $ms): void
    {
        $this->dbMs += $ms;
        $this->dbQueries++;
    }

    public function addExternal(float $ms): void
    {
        $this->externalMs += $ms;
    }

    public function elapsedMs(): float
    {
        return $this->stoppedMs ?? (hrtime(true) - $this->startedAt) / 1e6;
    }

    public function dbMs(): float
    {
        return $this->dbMs;
    }

    public function dbQueries(): int
    {
        return $this->dbQueries;
    }

    public function externalMs(): float
    {
        return $this->externalMs;
    }
}
