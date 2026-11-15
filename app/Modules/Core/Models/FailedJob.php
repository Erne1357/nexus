<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Un trabajo de la cola que falló (tabla `failed_jobs` de Laravel), solo para leerlo: reintentarlo o descartarlo pasa
 * por el failer de Laravel (RetryFailedJob, ForgetFailedJob), que lo busca por su `uuid`. Su llave es el `uuid`; el `id`
 * entero no sale del servidor.
 *
 * @property string $uuid
 * @property string $queue
 * @property string $payload
 * @property string $exception
 */
#[Table('failed_jobs', key: 'uuid', timestamps: false)]
class FailedJob extends Model
{
    use HasUuids;

    /** Renglones de la excepción que se muestran en la lista. */
    public const int HEAD_LINES = 3;

    /** Renglones de la excepción que se muestran en el drawer. */
    public const int SHOWN_LINES = 40;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['failed_at' => 'immutable_datetime'];
    }

    /**
     * El nombre del trabajo, de `payload.displayName` (la clase, o el listener o el evento).
     *
     * @return Attribute<string, never>
     */
    protected function jobName(): Attribute
    {
        return Attribute::get(fn (): string => (string) (json_decode($this->payload, true)['displayName'] ?? 'Trabajo desconocido'));
    }

    /**
     * Las primeras líneas de la excepción, para la lista.
     *
     * @return Attribute<string, never>
     */
    protected function exceptionHead(): Attribute
    {
        return Attribute::get(fn (): string => self::redact(implode("\n", array_slice(preg_split('/\R/', $this->exception) ?: [], 0, self::HEAD_LINES))));
    }

    /**
     * La excepción para el drawer: las primeras `SHOWN_LINES` líneas y sin lo que parezca un token.
     *
     * @return Attribute<string, never>
     */
    protected function exceptionShown(): Attribute
    {
        return Attribute::get(function (): string {
            $lines = preg_split('/\R/', $this->exception) ?: [];
            $shown = implode("\n", array_slice($lines, 0, self::SHOWN_LINES));

            return self::redact($shown).(count($lines) > self::SHOWN_LINES ? "\n… (".(count($lines) - self::SHOWN_LINES).' líneas más)' : '');
        });
    }

    /**
     * Oculta el valor de `token=` y `signature=` (enlaces con firma de las invitaciones y de restablecer contraseña).
     */
    public static function redact(string $text): string
    {
        return (string) preg_replace('/\b((?:token|signature)=)[^&\s"\')\]]+/i', '$1[oculto]', $text);
    }

    /**
     * El mensaje de la excepción en una línea, para la bitácora.
     */
    public function summary(): string
    {
        return Str::limit(strtok($this->exception, "\n") ?: '', 200);
    }
}
