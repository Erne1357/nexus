<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Enums\MailStatus;
use App\Modules\Core\Models\MailLog;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\RedactsSecrets;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Anota un correo en el registro (CF-S8): destinatarios, asunto, tipo, el usuario si algún destinatario es el correo de
 * uno, el estado y, si falló, el error. Nunca recibe el cuerpo, porque lleva enlaces con token. El registro es de solo
 * agregar y no es bitácora: no deja entrada en ella.
 */
final class RecordMail
{
    /**
     * @param  list<string>  $recipients
     */
    public function handle(array $recipients, string $subject, string $kind, MailStatus $status = MailStatus::Sent, ?string $error = null): MailLog
    {
        $recipients = array_values(array_unique(array_filter($recipients)));

        return MailLog::query()->create([
            'to' => implode(', ', $recipients),
            'subject' => $subject,
            'kind' => $kind,
            'user_id' => $this->userOf($recipients)?->getKey(),
            'status' => $status,
            'error' => $error === null ? null : Str::limit(RedactsSecrets::redact($error), 1000),
            'sent_at' => now(),
        ]);
    }

    /**
     * @param  list<string>  $recipients
     */
    private function userOf(array $recipients): ?User
    {
        if ($recipients === []) {
            return null;
        }

        return User::query()
            ->whereIn(DB::raw('lower(email)'), array_map(mb_strtolower(...), $recipients))
            ->orderBy('username')
            ->first();
    }
}
