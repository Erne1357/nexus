<?php

namespace App\Modules\Core\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * El correo de prueba de la cuenta de envío (tarea 1.29, CF-S9): lo pide el admin en «Correos y colas» y sale en ese
 * momento, sin cola, siempre por el transporte `microsoft-graph`, aunque MAIL_MAILER diga otro, porque prueba la
 * cuenta conectada. Usa el diseño de correo de Nexus y no lleva enlaces.
 */
final class MailAccountTest extends Notification
{
    public function __construct(public readonly string $requestedBy, public readonly string $account) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->mailer('microsoft-graph')
            ->subject('Correo de prueba de Nexus')
            ->greeting('Hola')
            ->line("{$this->requestedBy} pidió este correo de prueba desde Configuración → Correos y colas de Nexus.")
            ->line("Si te llegó, el correo de Nexus ya sale por la cuenta {$this->account}. No tienes que hacer nada más.");
    }
}
