<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\HeldPositionMail;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\AcademicLoadNotice;
use App\Modules\Core\Notifications\PositionRef;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

/**
 * Deja en espera el correo de un aviso de puesto cuyo puesto todavía no tiene buzón (CE-02, decisión del usuario del 26
 * de noviembre de 2026): la notificación serializada y cifrada con APP_KEY (lleva nombres y enlaces; serializada a secas
 * trae bytes nulos que el texto de PostgreSQL no guarda), su id, a quién iba, el puesto, si es de la carga académica
 * (MN-05), el tipo y el asunto. Lo llama la guardia HoldPositionMail al salir el correo.
 *
 * Es idempotente: si la cola vuelve a entregar el mismo correo, el id de la notificación ya está y no se guarda otro; si
 * es una liberación que vuelve a esperar (el buzón se desactivó entre que se tomó y se mandó), `$heldId` es su renglón y
 * solo vuelve a quedar en espera. Como el registro de correos, es una cola y no bitácora: no deja entrada en ella.
 */
final class RecordHeldPositionMail
{
    public function handle(User $user, Notification $notification, PositionRef $position, ?string $heldId = null): HeldPositionMail
    {
        if ($heldId !== null && ($held = HeldPositionMail::query()->find($heldId)) !== null) {
            $held->update(['released_at' => null]);

            return $held;
        }

        return HeldPositionMail::query()->createOrFirst(['notification_id' => $notification->id ?? (string) Str::uuid7()], [
            'user_id' => $user->getKey(),
            'role' => $position->role,
            ...$position->columns(),
            'academic_load' => $notification instanceof AcademicLoadNotice,
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
