<?php

namespace App\Modules\Core\Notifications;

use App\Modules\Core\Support\DefaultPassword;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * El correo de la invitación de acceso (AC-07), al correo institucional: el usuario, la contraseña por defecto, el
 * enlace para activar el acceso y el aviso de que tendrá que cambiarla al entrar. Va solo por `mail` (no es un aviso
 * de la campana) y sale de la cola, después del commit, cifrado (lleva un enlace vivo). La contraseña se lee de la
 * configuración al armar el correo: nunca viaja en la cola ni se guarda. Si el correo no sale de verdad sino que se
 * escribe en el log o se guarda en memoria (transporte `log` o `array`), no la lleva: el correo dice que se la da su
 * jefe o la administración (ADR-0024).
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
        $transport = config('mail.mailers.'.config('mail.default').'.transport');
        $passwordLine = in_array($transport, ['log', 'array'], true)
            ? 'Tu contraseña inicial te la da tu jefe de departamento o la administración de Nexus.'
            : 'Tu contraseña inicial es: '.app(DefaultPassword::class)->value();

        return (new MailMessage)
            ->subject('Tu acceso a Nexus')
            ->greeting("Hola, {$this->name}")
            ->line('Ya puedes entrar a Nexus, el sistema académico del ITCJ.')
            ->line("Tu usuario es tu RFC: {$this->username}")
            ->line($passwordLine)
            ->line('Antes de entrar, activa tu acceso con el botón. El enlace vence el '.$this->expiresAt->timezone(config('app.timezone'))->translatedFormat('j \d\e F \a \l\a\s H:i').'.')
            ->action('Activar mi acceso', $this->url)
            ->line('Al activarlo entras a Nexus, que te pedirá cambiar la contraseña por una propia.')
            ->line('Si no esperabas este correo, ignóralo: sin activar el enlace nadie puede entrar con tu usuario.');
    }
}
