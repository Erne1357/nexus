<?php

namespace App\Modules\AcademicLoad\Exports;

use App\Modules\AcademicLoad\Enums\ExportState;
use App\Modules\AcademicLoad\Jobs\GeneratePublishedExport;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Los archivos de lo publicado (tareas 4.5 y 4.6): los pide, dice en qué van y los entrega. El archivo vive en el disco
 * `exports`, nombrado por `PublishedExport::key()`, así que un exporte ya hecho se descarga sin volver a armarse hasta
 * que una versión nueva cambia la llave. Armarlo es de la cola (GeneratePublishedExport): una sola vez a la vez por
 * llave. Mientras, la marca «en camino» vive en la caché con la hora en que se pidió, y la de «fallido» se queda unos
 * minutos con un mensaje para quien lo pidió, nunca el error interno, que va al registro.
 *
 * Si la cola no lo deja listo en WAIT_SECONDS (el worker caído o atorado; incidencia F-05 del ensayo 8.11), el exporte
 * queda «tardando más de lo normal» (ExportState::Stalled) y retry() lo vuelve a encolar aunque sigan la marca y el
 * candado de job único, que duran 35 minutos.
 *
 * Sin estado propio, para Octane.
 */
final class PublishedExports
{
    public const string DISK = 'exports';

    /** Más que el `retry_after` de la cola (31 minutos): un job atorado vuelve a la cola antes de que esto expire. */
    private const int PENDING_MINUTES = 35;

    private const int FAILED_MINUTES = 10;

    /**
     * Cuánto se espera un exporte en camino antes de decir que tarda más de lo normal y dejar reintentar (supuesto por
     * confirmar). Armar el más grande tarda segundos (el PDF de un departamento con la forma de la demo masiva, medido en
     * desarrollo el 19 de noviembre de 2026: 2.7 s con 252 grupos y 11.2 s con 672, como Ciencias Económico-
     * Administrativas) y el worker toma un job nuevo en un segundo, así que dos minutos solo se pasan con el worker caído o
     * atorado en un job largo (un lote de importación o una corrida del motor).
     */
    public const int WAIT_SECONDS = 120;

    /**
     * En qué va: listo, fallido, en camino (Stalled si se pidió hace más de WAIT_SECONDS) o sin pedir. Una marca «en
     * camino» sin la hora en que se pidió (de antes de la F-05) cuenta como vencida.
     */
    public function state(PublishedExport $export): ExportState
    {
        if ($this->disk()->exists($this->path($export))) {
            return ExportState::Ready;
        }

        if (Cache::has($this->failedKey($export))) {
            return ExportState::Failed;
        }

        $requestedAt = Cache::get($this->pendingKey($export));

        return match (true) {
            $requestedAt === null => ExportState::Missing,
            ! is_int($requestedAt) || now()->getTimestamp() - $requestedAt > self::WAIT_SECONDS => ExportState::Stalled,
            default => ExportState::Pending,
        };
    }

    /**
     * Lo manda a la cola si no está listo, en camino ni fallido hace poco (el fallido espera unos minutos para no llenar
     * la cola con un error que se repite). Devuelve en qué quedó (con la cola síncrona, ya listo o fallido).
     */
    public function request(PublishedExport $export): ExportState
    {
        $state = $this->state($export);

        if ($state !== ExportState::Missing) {
            return $state;
        }

        Cache::put($this->pendingKey($export), now()->getTimestamp(), now()->addMinutes(self::PENDING_MINUTES));

        try {
            GeneratePublishedExport::dispatch($export);
        } catch (Throwable $exception) {
            if (config('queue.default') !== 'sync') {
                $this->fail($export, $exception);
            }
        }

        return $this->state($export);
    }

    /**
     * Vuelve a encolar un exporte que tarda más de lo normal (Stalled): suelta el candado de job único del que sigue en la
     * cola, porque si no la cola se traga el nuevo, y lo pide otra vez, con una espera nueva. Si el viejo llega a correr,
     * el primero que termine lo deja listo y el otro ya no lo arma (GeneratePublishedExport::handle()). En cualquier otro
     * estado no hace nada. Devuelve en qué quedó.
     */
    public function retry(PublishedExport $export): ExportState
    {
        if ($this->state($export) !== ExportState::Stalled) {
            return $this->state($export);
        }

        (new UniqueLock(Cache::store()))->release(new GeneratePublishedExport($export));
        Cache::forget($this->pendingKey($export));

        return $this->request($export);
    }

    /**
     * Arma el archivo y lo deja listo. Lo llama la cola; escribe a un temporal y lo mueve, para que nadie descargue un
     * archivo a medias.
     */
    public function generate(PublishedExport $export): void
    {
        $path = $this->path($export);
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.part';

        try {
            $this->disk()->put($temporary, $export->build());
            $this->disk()->move($temporary, $path);
        } finally {
            $this->disk()->delete($temporary);
        }

        Cache::forget($this->pendingKey($export));
        Cache::forget($this->failedKey($export));
    }

    /**
     * Lo deja fallido unos minutos con un mensaje para quien lo pidió; el error queda en el registro.
     */
    public function fail(PublishedExport $export, Throwable $exception): void
    {
        report($exception);
        Cache::put($this->failedKey($export), 'No se pudo generar el archivo. Vuelve a intentarlo en unos minutos.', now()->addMinutes(self::FAILED_MINUTES));
        Cache::forget($this->pendingKey($export));
    }

    public function failure(PublishedExport $export): ?string
    {
        return Cache::get($this->failedKey($export));
    }

    /**
     * La descarga de un exporte listo para quien lo puede descargar (PublishedExportPolicy): 403 sin el permiso, 404 si
     * no está listo.
     */
    public function download(PublishedExport $export): StreamedResponse
    {
        Gate::authorize('download', $export);
        abort_unless($this->state($export) === ExportState::Ready, 404);

        return $this->disk()->download($this->path($export), $export->filename(), ['Content-Type' => $export->mimeType()]);
    }

    /**
     * `published/<key>.<extensión del nombre>`.
     *
     * @throws InvalidArgumentException si la llave trae algo más que letras, dígitos, guion o guion bajo, o el nombre no
     *                                  termina en una extensión corta de letras y dígitos
     */
    public function path(PublishedExport $export): string
    {
        $key = $export->key();
        $extension = strtolower(pathinfo($export->filename(), PATHINFO_EXTENSION));

        if (preg_match('/^[A-Za-z0-9_-]+$/', $key) !== 1) {
            throw new InvalidArgumentException("Llave de exporte no válida: {$key}");
        }

        if (preg_match('/^[a-z0-9]{1,5}$/', $extension) !== 1) {
            throw new InvalidArgumentException("Extensión de exporte no válida: {$extension}");
        }

        return 'published/'.$key.'.'.$extension;
    }

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    private function pendingKey(PublishedExport $export): string
    {
        return 'published-export:pending:'.$export->key();
    }

    private function failedKey(PublishedExport $export): string
    {
        return 'published-export:failed:'.$export->key();
    }
}
