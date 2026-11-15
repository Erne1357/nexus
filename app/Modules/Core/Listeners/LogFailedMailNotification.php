<?php

namespace App\Modules\Core\Listeners;

use App\Modules\Core\Actions\RecordMail;
use App\Modules\Core\Enums\MailStatus;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * Anota en el registro de correos (CF-S8) una notificación por correo que falló. El asunto se toma de la notificación
 * si se puede armar sin efectos; si no, queda vacío. Las de otros canales no entran: no son correo.
 */
final class LogFailedMailNotification
{
    public function __construct(private readonly RecordMail $record) {}

    public function handle(NotificationFailed $event): void
    {
        if ($event->channel !== 'mail') {
            return;
        }

        $recipient = $event->notifiable instanceof AnonymousNotifiable
            ? $event->notifiable->routeNotificationFor('mail')
            : ($event->notifiable?->email ?? null);

        $this->record->handle(
            $this->addresses($recipient),
            $this->subject($event->notification, $event->notifiable),
            class_basename($event->notification),
            MailStatus::Failed,
            (string) ($event->data['message'] ?? 'No se pudo enviar el correo.'),
        );
    }

    private function subject(Notification $notification, mixed $notifiable): string
    {
        try {
            return (string) $notification->toMail($notifiable)->subject;
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * `routeNotificationFor('mail')` da un correo, una lista o `correo => nombre`.
     *
     * @return list<string>
     */
    private function addresses(mixed $recipient): array
    {
        $addresses = [];

        foreach ((array) $recipient as $key => $value) {
            $addresses[] = (string) (is_string($key) ? $key : $value);
        }

        return $addresses;
    }
}
