<?php

namespace App\Modules\Staffing\Jobs;

use App\Modules\Core\Models\Term;
use App\Modules\Staffing\Actions\RefreshTeacherTermLoads;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Recalcula en la cola `teacher_term_loads` de un periodo (RefreshTeacherTermLoads; tarea 3.12). Lo despacha
 * QueueTeacherLoadRefresh con 5 segundos de espera, para juntar varios cambios seguidos (asignar a un docente y
 * quitárselo a otro, una importación de plazas): espera uno solo en la cola por periodo, hasta que empieza a correr.
 * Como un aviso que llega mientras otro espera se descarta, siempre recalcula todo el periodo y no solo al docente
 * que cambió. Si llega otro cambio mientras corre, se encola de nuevo y no se encima con la corrida en curso.
 * Recalcular es idempotente, así que reintentar es seguro.
 */
final class RefreshTeacherLoads implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public const int DELAY_SECONDS = 5;

    public int $maxExceptions = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 600;

    public function __construct(public string $termId) {}

    /**
     * Lo encola con la espera de siempre.
     */
    public static function queueFor(string $termId): void
    {
        self::dispatch($termId)->delay(now()->addSeconds(self::DELAY_SECONDS));
    }

    public function handle(RefreshTeacherTermLoads $refresh): void
    {
        $term = Term::query()->find($this->termId);

        if ($term !== null) {
            $refresh->handle($term);
        }
    }

    public function uniqueId(): string
    {
        return $this->termId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("teacher-loads:{$this->termId}"))->releaseAfter(10)->expireAfter($this->timeout)];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }
}
