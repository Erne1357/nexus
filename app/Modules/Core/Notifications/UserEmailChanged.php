<?php

namespace App\Modules\Core\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * El aviso al correo **anterior** de una cuenta cuando el admin le cambia el correo en el panel (CF-S3, confirmado el 15
 * de noviembre de 2026): quién lo cambió y cuándo, y el correo nuevo parcialmente oculto (`maskedEmail()`), sin enlaces.
 * Así el dueño se entera si alguien se llevó su cuenta a otro buzón. Va solo por `mail`, a la dirección anterior
 * (`Notification::route('mail', …)`, UpdateUserProfile), y sale de la cola después del commit. Usa el diseño de correo
 * de Nexus.
 */
final class UserEmailChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $username,
        public readonly string $name,
        public readonly string $changedBy,
        public readonly CarbonImmutable $changedAt,
        public readonly ?string $newEmail,
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
        $when = $this->changedAt->timezone(config('app.timezone'))->translatedFormat('j \d\e F \d\e Y \a \l\a\s H:i');

        return (new MailMessage)
            ->subject('Cambió el correo de tu cuenta de Nexus')
            ->greeting("Hola, {$this->name}")
            ->line("{$this->changedBy} cambió el correo de tu usuario {$this->username} en Nexus, el sistema académico del ITCJ, el {$when}.")
            ->line($this->newEmail === null
                ? 'Tu cuenta ya no tiene correo institucional.'
                : 'El correo nuevo es '.self::maskedEmail($this->newEmail).'. Desde ahora los avisos de Nexus llegan ahí.')
            ->line('Si no lo esperabas, habla con la administración de Nexus.');
    }

    /**
     * El correo con lo de antes de la `@` oculto salvo sus dos primeras letras (una, si es corto): «ro•••@cdjuarez.tecnm.mx».
     */
    public static function maskedEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $visible = mb_strlen($local) > 3 ? 2 : 1;

        return mb_substr($local, 0, $visible).'•••'.($domain === '' ? '' : "@{$domain}");
    }
}
