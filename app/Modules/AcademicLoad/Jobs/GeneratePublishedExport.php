<?php

namespace App\Modules\AcademicLoad\Jobs;

use App\Modules\AcademicLoad\Exports\PublishedExport;
use App\Modules\AcademicLoad\Exports\PublishedExports;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Arma en la cola un exporte de lo publicado (PublishedExports::generate): un PDF de cientos de grupos o el Excel del
 * campus tardan más de un segundo (CLAUDE.md, arquitectura, punto 7). Único por llave mientras espera o corre, así
 * que muchos que piden el mismo PDF a la vez no lo arman dos veces. Un solo intento: si falla, failed() lo deja
 * fallido unos minutos y quien lo pidió puede volver a pedirlo.
 *
 * `timeout` queda por debajo del `retry_after` de la conexión (config/queue.php).
 */
final class GeneratePublishedExport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /** Como la marca «en camino» de PublishedExports: más que el `retry_after` de la cola. */
    public int $uniqueFor = 2100;

    public function __construct(public PublishedExport $export) {}

    public function uniqueId(): string
    {
        return $this->export->key();
    }

    public function handle(PublishedExports $exports): void
    {
        $exports->generate($this->export);
    }

    public function failed(Throwable $exception): void
    {
        app(PublishedExports::class)->fail($this->export, $exception);
    }
}
