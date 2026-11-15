<?php

namespace App\Modules\Core\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * El correo de la invitación de acceso (AC-07), al correo institucional: el usuario y el enlace para activar el acceso,
 * donde elige su contraseña. Dice «Tu usuario es: …» sin llamarlo RFC, porque el del personal que invita el admin puede
 * ser un número de empleado (CF-07, supuesto por confirmar). No lleva ninguna contraseña (confirmado el 14 de noviembre de 2026: el enlace
 * basta). Va solo por `mail` (no es un aviso de la campana) y sale de la cola, después del commit, cifrado (lleva un
 * enlace vivo).
 */
final class AccessInvitation extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $username,
        public readonly string $name,
        public readonly string $url,
        public readonly CarbonImmutable $expiresAt,
    ) {
        $this->afterCommit();
    }

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
            ->subject('Tu acceso a Nexus')
            ->greeting("Hola, {$this->name}")
            ->line('Ya puedes entrar a Nexus, el sistema académico del ITCJ.')
            ->line("Tu usuario es: {$this->username}")
            ->line('Para activar tu acceso, abre el enlace y elige tu contraseña. Vence el '.$this->expiresAt->timezone(config('app.timezone'))->translatedFormat('j \d\e F \a \l\a\s H:i').'.')
            ->action('Activar mi acceso', $this->url)
            ->line('Si no esperabas este correo, ignóralo: sin activar el enlace nadie puede entrar con tu usuario.');
    }
}
