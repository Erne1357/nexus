<?php

namespace App\Modules\Core\Observability;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Una línea por petición (`request.done`) y una por job (`job.done` | `job.failed`).
 *
 * Regla: sin datos personales, solo ids. Ni la URI con valores, ni la query, ni nombres, ni correos: la ruta sale por
 * su nombre o su plantilla, y las claves son las de estas listas y ninguna más.
 */
final class CanonicalLog
{
    /** @var list<string> */
    public const array REQUEST_KEYS = [
        'request_id', 'method', 'route', 'module', 'livewire', 'status', 'duration_ms', 'db_ms', 'db_queries', 'external_ms', 'user_id', 'role', 'release',
    ];

    /** @var list<string> */
    public const array JOB_KEYS = [
        'request_id', 'job', 'queue', 'attempt', 'queue_wait_ms', 'duration_ms', 'db_ms', 'module', 'outcome',
    ];

    public static function levelFor(int $status, float $durationMs): string
    {
        return match (true) {
            $status >= 500 => 'error',
            $durationMs > 1000 => 'warning',
            default => 'info',
        };
    }

    public function request(Request $request, Response $response): void
    {
        $metrics = app(OperationMetrics::class);
        $route = $request->route();
        $status = $response->getStatusCode();
        $duration = round($metrics->elapsedMs(), 2);

        Log::log(self::levelFor($status, $duration), 'request.done', [
            'request_id' => Context::get('request_id'),
            'method' => $request->method(),
            'route' => $route?->getName() ?? $route?->uri(),
            'module' => Context::get('module', 'core'),
            'livewire' => Context::get('livewire'),
            'status' => $status,
            'duration_ms' => $duration,
            'db_ms' => round($metrics->dbMs(), 2),
            'db_queries' => $metrics->dbQueries(),
            'external_ms' => round($metrics->externalMs(), 2),
            'user_id' => auth()->id(),
            // Directo de la sesión: Actor::activeRole() consulta la base y escribe la sesión.
            'role' => $request->hasSession() ? $request->session()->get('core.active_role') : null,
            'release' => config('health.release'),
        ]);
    }

    /** La implementa la tarea de jobs; la firma queda fijada aquí. */
    public function job(Job $job, string $outcome): void
    {
        // Se completa en la tarea 5 (job.done / job.failed).
    }
}
