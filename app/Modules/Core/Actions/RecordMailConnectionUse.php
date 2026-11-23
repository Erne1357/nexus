<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\MailConnection;
use App\Modules\Core\Support\RedactsSecrets;
use Illuminate\Support\Str;

/**
 * Anota en la cuenta de envío cómo le fue al último correo (tarea 1.29, CF-S9): si salió, la hora (`last_used_at`) y
 * borra el último error; si no, el error, sin secretos. Como el registro de correos (RecordMail), no deja bitácora: cada
 * correo ya queda en `mail_log`. Si la cuenta se desconectó mientras tanto, no hace nada.
 */
final class RecordMailConnectionUse
{
    public function handle(MailConnection $connection, ?string $error = null): void
    {
        MailConnection::query()->whereKey($connection->getKey())->update($error === null
            ? ['last_used_at' => now(), 'last_error' => null, 'updated_at' => now()]
            : ['last_error' => Str::limit(RedactsSecrets::redact($error), 1000), 'updated_at' => now()]);
    }
}
