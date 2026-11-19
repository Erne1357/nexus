<?php

namespace App\Modules\Staffing\Notifications;

use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\NotificationMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

/**
 * Llega al docente cuyos grupos cambiaron de horario al aplicar una solicitud de cambio con una alternativa del motor
 * (decidido por el usuario el 18 de noviembre de 2026: se le avisa al aplicar, cuando la solicitud queda aplicada): los
 * de la cadena y el de la solicitud, una línea por grupo con de dónde a dónde. Lleva a «Mi carga».
 */
final class ChangeRequestMovedTeacherGroups extends ChangeAlternativeNotice
{
    /**
     * @param  list<array{label: string, subject: ?string, from: string, to: string}>  $groups
     * @param  string  $requested  el grupo de la solicitud («SCD-1015 · 5A»)
     */
    public function __construct(
        private readonly array $groups,
        private readonly string $requested,
        private readonly string $termId,
        private readonly string $modalityId,
    ) {
        $this->afterCommit();
    }

    public static function typeKey(): string
    {
        return 'change-request-moved-teacher-groups';
    }

    public static function typeLabel(): string
    {
        return 'Cambió el horario de tus grupos';
    }

    /**
     * Lo que cambió, una línea por grupo.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        return array_map(fn (array $group): string => "{$group['label']}".($group['subject'] === null ? '' : " {$group['subject']}").": {$group['from']} → {$group['to']}.", $this->groups);
    }

    public function message(object $notifiable): NotificationMessage
    {
        return new NotificationMessage(
            title: $this->title(),
            body: Str::limit(implode(' ', $this->lines()), 200),
            icon: 'o-calendar-days',
            route: 'staffing.my-load',
            termId: $this->termId,
            tone: 'warning',
            modalityId: $this->modalityId,
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title())
            ->greeting($notifiable instanceof User ? "Hola, {$notifiable->name}" : 'Hola')
            ->line("Se aplicó la solicitud de cambio de {$this->requested} y con ella cambió el horario de ".(count($this->groups) === 1 ? 'tu grupo:' : 'tus grupos:'));

        foreach ($this->lines() as $line) {
            $mail->line($line);
        }

        return $mail->action('Ver mi carga', route('staffing.my-load'));
    }

    private function title(): string
    {
        return count($this->groups) === 1 ? "Cambió el horario de tu grupo {$this->groups[0]['label']}" : 'Cambió el horario de '.count($this->groups).' de tus grupos';
    }
}
