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
 *
 * Memoria (E-05 del ensayo 8.11): DomPDF tiene en memoria un objeto por cada nodo del documento completo, así que el PDF
 * de un departamento crece con sus grupos. Medido con la demo masiva el 19 de noviembre de 2026, ya con una tabla por
 * materia (semester.blade.php), el pico del proceso fue de 133 MB con Sistemas y Computación (237 grupos), 157 MB con
 * Ciencias Básicas (356) y 243 MB con Ciencias Económico-Administrativas (671), sobre los 128 MB del contenedor de
 * desarrollo y los 256 MB de producción (docker/php/prod.ini). Mientras arma, el job sube su tope a MEMORY_LIMIT, que
 * alcanza para unos 1,700 grupos en un departamento, y lo regresa al valor de antes al terminar (también si falla):
 * un worker de FrankenPHP o de `queue:work` atiende muchas peticiones o jobs y no debe quedarse con el tope alto.
 */
final class GeneratePublishedExport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /** Como la marca «en camino» de PublishedExports: más que el `retry_after` de la cola. */
    public int $uniqueFor = 2100;

    /** El tope de memoria mientras se arma un exporte (supuesto por confirmar: un worker a la vez en el servidor). */
    public const string MEMORY_LIMIT = '512M';

    public function __construct(public PublishedExport $export) {}

    public function uniqueId(): string
    {
        return $this->export->key();
    }

    public function handle(PublishedExports $exports): void
    {
        $previous = self::raiseMemoryLimit();

        try {
            $exports->generate($this->export);
        } finally {
            if ($previous !== null) {
                ini_set('memory_limit', $previous);
            }
        }
    }

    /**
     * Sube `memory_limit` a MEMORY_LIMIT si el actual es menor; -1 (sin límite) o uno mayor se quedan. Devuelve el valor
     * de antes si lo subió, para regresarlo al terminar, o null si no lo tocó.
     */
    private static function raiseMemoryLimit(): ?string
    {
        $current = (string) ini_get('memory_limit');

        if ($current !== '-1' && self::bytes($current) < self::bytes(self::MEMORY_LIMIT)) {
            ini_set('memory_limit', self::MEMORY_LIMIT);

            return $current;
        }

        return null;
    }

    /**
     * Los bytes de un valor de `memory_limit` («128M», «2G», «134217728»).
     */
    private static function bytes(string $limit): int
    {
        return (int) $limit * match (strtoupper(substr($limit, -1))) {
            'G' => 1024 ** 3,
            'M' => 1024 ** 2,
            'K' => 1024,
            default => 1,
        };
    }

    public function failed(Throwable $exception): void
    {
        app(PublishedExports::class)->fail($this->export, $exception);
    }
}
