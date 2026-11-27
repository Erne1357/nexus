<?php

namespace App\Modules\Staffing\Notifications;

use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\AcademicLoadNotice;
use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Core\Notifications\PositionNotice;
use App\Modules\Core\Notifications\PositionRef;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

/**
 * Llega a la coordinación de una carrera cuando el jefe aprueba una solicitud de cambio con una alternativa del motor
 * cuya cadena mueve un grupo de esa carrera (decidido por el usuario el 18 de noviembre de 2026: el jefe decide sin su
 * visto bueno y se le avisa al elegir). Dice qué grupo se mueve, de dónde a dónde y por qué solicitud; lleva al editor
 * de horarios con el grupo, porque la solicitud es de otra carrera y su pantalla no la muestra. Es un aviso de la carga (AcademicLoadNotice, MN-05): mientras
 * está oculta, el correo no sale.
 *
 * CE-02 (confirmado el 26 de noviembre de 2026): compete a la coordinación (PositionNotice): su correo va al buzón del
 * coordinador de la carrera dueña del grupo, en su campus y su modalidad, y, sin buzón, espera; la campana llega igual.
 * `career_id` y `campus_id` del grupo dicen el puesto; un aviso en cola de antes no los trae y sigue a la persona.
 */
final class ChangeAlternativeMovesCareerGroup extends ChangeAlternativeNotice implements AcademicLoadNotice, PositionNotice
{
    /**
     * @param  array{group_id: string, label: string, subject: ?string, career: ?string, campus: ?string, career_id?: ?string, campus_id?: ?string, from: string, to: string}  $group  el grupo que se mueve
     * @param  string  $requested  el grupo de la solicitud («SCD-1015 · 5A»)
     */
    public function __construct(
        private readonly array $group,
        private readonly string $requested,
        private readonly string $letter,
        private readonly string $termId,
        private readonly string $modalityId,
    ) {
        $this->afterCommit();
    }

    public static function typeKey(): string
    {
        return 'change-alternative-moves-career-group';
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->positionChannels() ?? parent::via($notifiable);
    }

    /**
     * CE-02: el coordinador de la carrera dueña del grupo, en el campus del grupo y la modalidad del aviso.
     */
    public function position(): ?PositionRef
    {
        $role = $this->addressedRole();
        $career = $this->group['career_id'] ?? null;

        return $role === null || $career === null ? null : PositionRef::of($role, career: $career, campus: $this->group['campus_id'] ?? null, modality: $this->modalityId);
    }

    public static function typeLabel(): string
    {
        return 'Grupo movido por una solicitud de cambio';
    }

    public function message(object $notifiable): NotificationMessage
    {
        return new NotificationMessage(
            title: $this->title(),
            body: Str::limit($this->line(), 200),
            icon: 'o-arrows-right-left',
            route: 'scheduling.editor',
            parameters: ['grupo' => $this->group['group_id'], ...($this->group['campus'] === null ? [] : ['campus' => $this->group['campus']])],
            termId: $this->termId,
            tone: 'warning',
            modalityId: $this->modalityId,
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->greeting($notifiable instanceof User ? "Hola, {$notifiable->name}" : 'Hola')
            ->line("El jefe del departamento aprobó la solicitud de cambio de {$this->requested} con la alternativa {$this->letter} del motor, que mueve un grupo de tu carrera para hacerle lugar.")
            ->line($this->line())
            ->line('El cambio se escribe en el horario cuando quien lo aplica lo deja aplicado.')
            ->action('Ver el grupo en el editor', route('scheduling.editor', ['grupo' => $this->group['group_id']]));
    }

    private function title(): string
    {
        return "Se mueve el grupo {$this->group['label']}".($this->group['career'] === null ? '' : " de {$this->group['career']}");
    }

    private function line(): string
    {
        $subject = $this->group['subject'] === null ? '' : "{$this->group['subject']}: ";

        return "{$subject}{$this->group['from']} → {$this->group['to']}. Por la solicitud de cambio de {$this->requested} (alternativa {$this->letter}).";
    }
}
