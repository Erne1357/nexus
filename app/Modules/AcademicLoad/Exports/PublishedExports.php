<?php

namespace App\Modules\AcademicLoad\Exports;

use App\Modules\AcademicLoad\Enums\ExportState;
use App\Modules\AcademicLoad\Jobs\GeneratePublishedExport;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Los archivos de lo publicado (tareas 4.5 y 4.6): los pide, dice en qué van y los entrega. El archivo vive en el disco
 * `exports`, nombrado por `PublishedExport::key()`, así que un exporte ya hecho se descarga sin volver a armarse hasta
 * que una versión nueva cambia la llave. Armarlo es de la cola (GeneratePublishedExport): una sola vez a la vez por
 * llave. Mientras, la marca «en camino» vive en la caché, y la de «fallido» se queda unos minutos con un mensaje para
 * quien lo pidió, nunca el error interno, que va al registro.
 *
 * Sin estado propio, para Octane.
 */
final class PublishedExports
{
    public const string DISK = 'exports';

    private const int PENDING_MINUTES = 15;

    private const int FAILED_MINUTES = 10;

    public function state(PublishedExport $export): ExportState
    {
        return match (true) {
            $this->disk()->exists($this->path($export)) => ExportState::Ready,
            Cache::has($this->failedKey($export)) => ExportState::Failed,
            Cache::has($this->pendingKey($export)) => ExportState::Pending,
            default => ExportState::Missing,
        };
    }

    /**
     * Lo manda a la cola si no está listo ni en camino; uno fallido se vuelve a pedir. Devuelve en qué quedó (con la
     * cola síncrona, ya listo o fallido).
     */
    public function request(PublishedExport $export): ExportState
    {
        $state = $this->state($export);

        if ($state === ExportState::Ready || $state === ExportState::Pending) {
            return $state;
        }

        Cache::forget($this->failedKey($export));
        Cache::put($this->pendingKey($export), true, now()->addMinutes(self::PENDING_MINUTES));

        try {
            GeneratePublishedExport::dispatch($export);
        } catch (Throwable) {
            // Con la cola síncrona el error del job llega hasta aquí; su failed() ya lo dejó fallido y lo reportó.
        }

        return $this->state($export);
    }

    /**
     * Arma el archivo y lo deja listo. Lo llama la cola; escribe a un temporal y lo mueve, para que nadie descargue un
     * archivo a medias.
     */
    public function generate(PublishedExport $export): void
    {
        $path = $this->path($export);
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.part';

        $this->disk()->put($temporary, $export->build());
        $this->disk()->move($temporary, $path);
        Cache::forget($this->pendingKey($export));
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
     * Si quien lo pide (nadie, en la consulta pública) puede descargarlo: lo público sí; lo demás, con su permiso en el
     * campus y la unidad del exporte.
     */
    public function allows(?User $user, PublishedExport $export): bool
    {
        $permission = $export->permission();

        if ($permission === null) {
            return true;
        }

        return $user !== null && app(Actor::class)->forUser($user)->allows($permission, $export->scope());
    }

    /**
     * La descarga de un exporte listo; 404 si no lo está.
     */
    public function download(PublishedExport $export): StreamedResponse
    {
        abort_unless($this->state($export) === ExportState::Ready, 404);

        return $this->disk()->download($this->path($export), $export->filename(), ['Content-Type' => $export->mimeType()]);
    }

    /**
     * `published/<key>.<extensión del nombre>`.
     *
     * @throws InvalidArgumentException si la llave trae algo más que letras, dígitos, guion o guion bajo
     */
    public function path(PublishedExport $export): string
    {
        $key = $export->key();

        if (preg_match('/^[A-Za-z0-9_-]+$/', $key) !== 1) {
            throw new InvalidArgumentException("Llave de exporte no válida: {$key}");
        }

        $extension = strtolower(pathinfo($export->filename(), PATHINFO_EXTENSION));

        return 'published/'.$key.($extension === '' ? '' : '.'.$extension);
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
