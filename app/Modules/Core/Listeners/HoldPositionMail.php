<?php

namespace App\Modules\Core\Listeners;

use App\Modules\Core\Actions\RecordHeldPositionMail;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\PositionNotice;
use App\Modules\Core\Queries\PositionMailboxQuery;
use Illuminate\Notifications\Events\NotificationSending;

/**
 * CE-02 (confirmado el 26 de noviembre de 2026): el correo de un aviso de puesto (PositionNotice con puesto) va al buzón
 * del puesto. Si el puesto todavía no tiene buzón activo, la campana ya se guardó y el correo queda en espera
 * (`held_position_mails`, RecordHeldPositionMail) hasta que el admin lo capture; si otra copia del mismo aviso ya lleva el
 * correo a ese puesto (NotifyHolders), esta no lo manda. En los dos casos cancela el canal `mail` de este destinatario.
 * Se decide al mandar, también al salir de la cola. Va después de HoldAcademicLoadMail (MN-05) y antes de la guardia de
 * CE-01 (GuardMailRecipient).
 */
final class HoldPositionMail
{
    public function __construct(private readonly PositionMailboxQuery $mailboxes, private readonly RecordHeldPositionMail $hold) {}

    /**
     * Falso cancela ese canal para ese destinatario (NotificationSender lo pregunta con `until`).
     */
    public function handle(NotificationSending $event): ?bool
    {
        $notification = $event->notification;

        if ($event->channel !== 'mail' || ! $notification instanceof PositionNotice || ($position = $notification->position()) === null) {
            return null;
        }

        if ($notification instanceof NexusNotification && $notification->positionMailShared()) {
            return false;
        }

        if ($this->mailboxes->mailboxFor($position) !== null || ! $event->notifiable instanceof User) {
            return null;
        }

        $this->hold->handle($event->notifiable, $notification, $position, $notification instanceof NexusNotification ? $notification->heldPositionMailId() : null);

        return false;
    }
}
