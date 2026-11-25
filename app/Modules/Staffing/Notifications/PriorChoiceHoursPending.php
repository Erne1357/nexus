<?php

namespace App\Modules\Staffing\Notifications;

use App\Modules\Core\Notifications\NotificationMessage;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Llega una sola vez, al publicar la selección, al docente con elección previa cuyas horas quedan arriba de la banda de
 * su base: tiene que confirmar con la casilla de responsabilidad para que sus horas de más queden justificadas (SEL-16,
 * tarea 1.45). Campana y correo, con el respaldo al correo personal de SEL-15. Lleva a «Oferta». Llamada del coordinador,
 * supuesto por confirmar.
 */
final class PriorChoiceHoursPending extends SelectionTeacherNotice
{
    public function __construct(
        private readonly string $termId,
        private readonly string $modalityId,
        private readonly string $department,
        private readonly int $hours,
        private readonly int $base,
    ) {}

    public static function typeKey(): string
    {
        return 'prior-choice-hours-pending';
    }

    public static function typeLabel(): string
    {
        return 'Confirmar tus horas';
    }

    public function message(object $notifiable): NotificationMessage
    {
        return new NotificationMessage(
            title: 'Confirma tus horas de la elección previa',
            body: "Tus grupos suman {$this->hours} h y tu base es de {$this->base} h: marca que estás de acuerdo para confirmarlas",
            icon: 'o-exclamation-triangle',
            route: 'staffing.offer',
            termId: $this->termId,
            tone: 'warning',
            modalityId: $this->modalityId,
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirma tus horas de carga')
            ->greeting(self::greetingFor($notifiable))
            ->line("Tus grupos de {$this->department} ya vienen escritos en la relación de materias y suman {$this->hours} h; tu base es de {$this->base} h.")
            ->line('Como pasan de lo que te toca, necesitamos que confirmes en «Oferta» que estás de acuerdo con tener esas horas. Solo es marcar la casilla y confirmar.')
            ->action('Confirmar mis horas', route('staffing.offer'));
    }
}
