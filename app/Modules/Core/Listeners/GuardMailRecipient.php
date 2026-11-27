<?php

namespace App\Modules\Core\Listeners;

use App\Modules\Core\Actions\RecordMail;
use App\Modules\Core\Enums\MailStatus;
use App\Modules\Core\Notifications\InstitutionalMail;
use App\Modules\Core\Notifications\PersonalMailExceptions;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * CE-01 (confirmado el 26 de noviembre de 2026): la guardia del correo. Antes de que salga un correo de una notificación
 * (canal `mail`, también al salir de la cola y con `notifyNow`), resuelve su destino como lo hará MailChannel (el usuario
 * con la notificación, o la ruta de una AnonymousNotifiable) y, si alguna dirección no es institucional
 * (InstitutionalMail) y el aviso no es de una excepción declarada (PersonalMailExceptions: SEL-13 y SEL-15), cancela ese
 * canal para ese destinatario y lo anota en el registro de correos (CF-S8) como fallido con el motivo empezando por
 * «Bloqueado (CE-01)» (MailStatus::BLOCKED_PREFIX), que la pantalla muestra como «Bloqueado», con el destino y el asunto. La campana (`database`) no se toca. Sin destino no hace nada: MailChannel tampoco manda. Va después de
 * HoldAcademicLoadMail (MN-05), que retiene antes lo de la carga oculta. Si anotar falla, se reporta y el correo sigue
 * bloqueado: la guardia nunca deja pasar un correo por un error.
 */
final class GuardMailRecipient
{
    public function __construct(private readonly RecordMail $record, private readonly PersonalMailExceptions $exceptions) {}

    /**
     * Falso cancela el canal `mail` para ese destinatario (NotificationSender lo pregunta con `until`).
     */
    public function handle(NotificationSending $event): ?bool
    {
        if ($event->channel !== 'mail') {
            return null;
        }

        $addresses = $this->addresses($event->notifiable, $event->notification);
        $outside = array_values(array_filter($addresses, fn (string $address): bool => ! InstitutionalMail::isInstitutional($address)));

        if ($outside === [] || $this->exceptions->ruleFor($event->notification) !== null) {
            return null;
        }

        try {
            $this->record->handle(
                $addresses,
                $this->subject($event->notifiable, $event->notification),
                class_basename($event->notification),
                MailStatus::Failed,
                MailStatus::BLOCKED_PREFIX.': '.implode(', ', $outside).' no es institucional y este aviso no tiene una excepción declarada.',
            );
        } catch (Throwable $exception) {
            report($exception);
        }

        return false;
    }

    /**
     * Las direcciones del destino, como las lee MailChannel: una cadena, una lista o un mapa correo => nombre.
     *
     * @return list<string>
     */
    private function addresses(object $notifiable, Notification $notification): array
    {
        $route = $notifiable instanceof AnonymousNotifiable
            ? $notifiable->routeNotificationFor('mail')
            : (method_exists($notifiable, 'routeNotificationFor') ? $notifiable->routeNotificationFor('mail', $notification) : null);

        $addresses = [];

        foreach ((array) $route as $key => $value) {
            $address = trim((string) (is_string($key) ? $key : $value));

            if ($address !== '') {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    private function subject(object $notifiable, Notification $notification): string
    {
        try {
            return method_exists($notification, 'toMail') ? (string) ($notification->toMail($notifiable)->subject ?? '') : '';
        } catch (Throwable) {
            return '';
        }
    }
}
