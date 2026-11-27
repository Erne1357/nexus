<?php

namespace App\Modules\Core\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * El correo con el enlace para restablecer la contraseña (AC-16), al correo institucional del usuario: su usuario, el
 * enlace y cuánto vale. Va solo por `mail` (no es un aviso de la campana). No es ShouldQueue a propósito: el enlace
 * lleva el token, que no debe viajar en la cola. Lo manda SendPasswordResetLink, que ya corre dentro de un trabajo de
 * la cola (SendPasswordResetLinkJob). Si el correo no sale de verdad sino que se escribe en el log o se guarda en
 * memoria (transporte `log` o `array`), no lleva el enlace, que serviría a quien lea el log: dice que lo pida otra vez
 * cuando el correo esté configurado (ADR-0024).
 */
final class PasswordResetLink extends Notification
{
    public function __construct(
        public readonly string $username,
        public readonly string $name,
        #[SensitiveParameter] public readonly string $url,
        public readonly int $minutes,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Restablece tu contraseña de Nexus')
            ->greeting("Hola, {$this->name}")
            ->line("Se pidió restablecer la contraseña de tu usuario {$this->username} en Nexus, el sistema académico del ITCJ.");

        if (in_array(config('mail.mailers.'.config('mail.default').'.transport'), ['log', 'array'], true)) {
            return $message
                ->line('Este servidor todavía no manda correos, así que este no lleva el enlace. Pide el enlace otra vez cuando el correo esté configurado, o pídele al administrador del sistema que restablezca tu contraseña.')
                ->line('Si no lo pediste, ignora este correo: tu contraseña no cambia.');
        }

        return $message
            ->action('Elegir una contraseña nueva', $this->url)
            ->line("El enlace vence en {$this->minutes} minutos y sirve una sola vez.")
            ->line('Si no lo pediste, ignora este correo: tu contraseña no cambia.');
    }
}
