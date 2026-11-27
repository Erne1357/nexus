<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * El acuse del último cambio de contraseña de una persona (tarea 1.30; DESIGN.md, "Pantallas de contraseña"): el folio
 * (el número de su renglón en la bitácora) y cuándo fue, para el talón que se desprende de la credencial al guardar.
 * Sale de la bitácora, nunca de la contraseña.
 */
final class PasswordReceiptQuery
{
    /**
     * @return array{folio: int, at: string}|null
     */
    public function latestFor(User $user): ?array
    {
        $entry = Activity::query()
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->whereIn('event', ['password-changed', 'password-reset-with-link'])
            ->latest('id')
            ->first(['id', 'created_at']);

        if ($entry === null) {
            return null;
        }

        return [
            'folio' => (int) $entry->getKey(),
            'at' => $entry->created_at->timezone(config('app.timezone'))->locale('es')->translatedFormat('j \d\e F \d\e Y, G:i'),
        ];
    }
}
