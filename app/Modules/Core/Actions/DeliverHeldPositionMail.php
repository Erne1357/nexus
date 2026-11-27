<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Authorization\AcademicLoadVisibility;
use App\Modules\Core\Models\HeldPositionMail;
use App\Modules\Core\Notifications\NexusNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Throwable;

/**
 * Manda un correo de puesto que se liberó (CE-02): solo si sigue tomado (`released_at`, sin fallar ni descartarse), solo
 * por `mail` y a quien iba (`notifyNow($aviso, ['mail'])`), así pasa por las guardias de siempre (MN-05, el buzón y CE-01).
 * La copia lleva el id de su renglón: si al mandarla el buzón ya no está, vuelve a quedar en espera en ese mismo renglón
 * (RecordHeldPositionMail) y no se pierde ni se duplica.
 *
 * - Si el aviso es de la carga y la carga está oculta, lo regresa a la espera sin mandarlo (MN-05).
 * - Si no se puede volver a armar (el cifrado o la clase ya no sirven), lo deja fallido: es permanente.
 * - Otro error (Microsoft no respondió, frenó…) se deja subir para que la cola reintente; cuando la cola se rinde,
 *   giveBack() lo regresa a la espera con un intento más, y al llegar a MAX_ATTEMPTS lo deja fallido.
 */
final class DeliverHeldPositionMail
{
    /** Cuántas veces se rinde la cola antes de dejarlo fallido (supuesto por confirmar). */
    public const int MAX_ATTEMPTS = 3;

    public function __construct(private readonly AcademicLoadVisibility $visibility) {}

    public function handle(string $id): void
    {
        $held = HeldPositionMail::query()->with('user')->find($id);

        if ($held === null || $held->released_at === null || $held->failed_at !== null || $held->discarded_at !== null) {
            return;
        }

        if ($held->academic_load && $this->visibility->isHidden()) {
            $held->update(['released_at' => null]);

            return;
        }

        try {
            $notification = Crypt::decrypt($held->notification);
        } catch (Throwable $exception) {
            $notification = $exception;
        }

        if (! $notification instanceof Notification || $held->user === null) {
            $held->update(['released_at' => null, 'failed_at' => now()]);
            report($notification instanceof Throwable ? $notification : new RuntimeException("El correo en espera {$id} no se pudo volver a armar."));

            return;
        }

        if ($notification instanceof NexusNotification) {
            $notification->carriesHeldPositionMail($held->getKey());
        }

        $held->user->notifyNow($notification, ['mail']);
    }

    /**
     * La cola se rindió con un error que se puede reintentar: vuelve a la espera con un intento más, o queda fallido.
     */
    public function giveBack(string $id): void
    {
        $held = HeldPositionMail::query()->find($id);

        if ($held === null || $held->failed_at !== null || $held->discarded_at !== null) {
            return;
        }

        $attempts = $held->attempts + 1;

        $held->update(['attempts' => $attempts, 'released_at' => null, 'failed_at' => $attempts >= self::MAX_ATTEMPTS ? now() : null]);
    }
}
