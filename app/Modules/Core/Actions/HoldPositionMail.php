<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\HeldPositionMail as HeldMail;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\PositionRef;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Deja en espera el correo de un aviso de puesto cuyo puesto todavía no tiene buzón (CE-02, decisión del usuario del 26
 * de noviembre de 2026): la notificación serializada y cifrada con APP_KEY (lleva nombres y enlaces; serializada a secas
 * trae bytes nulos que el texto de PostgreSQL no guarda), a quién iba, el puesto, el tipo y el asunto. Lo llama la guardia
 * HoldPositionMail al salir el correo. Como el registro de correos, es una cola y no bitácora: no deja entrada en ella.
 */
final class HoldPositionMail
{
    public function handle(User $user, Notification $notification, PositionRef $position): HeldMail
    {
        return HeldMail::query()->create([
            'user_id' => $user->getKey(),
            'role' => $position->role,
            ...$position->columns(),
            'kind' => class_basename($notification),
            'subject' => $this->subject($user, $notification),
            'notification' => Crypt::encrypt($notification),
            'held_at' => now(),
        ]);
    }

    private function subject(User $user, Notification $notification): ?string
    {
        try {
            return method_exists($notification, 'toMail') ? ($notification->toMail($user)->subject ?? null) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
