<?php

namespace App\Modules\Staffing\Notifications;

use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Staffing\Rules\SelectionSlots;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Llega al docente cuando se publica la selección de su departamento: le dice el día y la hora de su turno (SEL-11), por
 * la campana y por correo (diseño 6.9). Lleva a «Oferta», donde elige.
 */
final class SelectionTurnPublished extends SelectionTeacherNotice
{
    public function __construct(
        private readonly string $termId,
        private readonly string $modalityId,
        private readonly string $department,
        private readonly CarbonImmutable $startsAt,
        private readonly CarbonImmutable $endsAt,
    ) {}

    public static function typeKey(): string
    {
        return 'selection-turn-published';
    }

    public static function typeLabel(): string
    {
        return 'Turno de selección';
    }

    public function message(object $notifiable): NotificationMessage
    {
        return new NotificationMessage(
            title: 'Ya puedes consultar tu turno de selección',
            body: 'Tu turno de selección es el '.$this->day().' de '.$this->clock($this->startsAt, 'H:i').' a '.$this->clock($this->endsAt, 'H:i'),
            icon: 'o-clock',
            route: 'staffing.offer',
            termId: $this->termId,
            tone: 'info',
            modalityId: $this->modalityId,
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Tu turno de selección de carga: '.$this->day())
            ->greeting(self::greetingFor($notifiable))
            ->line("Ya se publicó el orden de selección de carga de {$this->department}.")
            ->line('Tu turno es el '.$this->day().' de '.$this->clock($this->startsAt).' a '.$this->clock($this->endsAt).'. En tu turno eliges tus grupos y su salón en «Oferta».')
            ->line('Te avisamos otra vez 10 minutos antes y cuando empiece. Si alguien termina antes, tu turno no se recorre.')
            ->action('Ver mi turno en Oferta', route('staffing.offer'));
    }

    private function day(): string
    {
        return $this->startsAt->setTimezone(SelectionSlots::TIMEZONE)->locale('es')->translatedFormat('l j \d\e F');
    }

    private function clock(CarbonImmutable $moment, string $format = 'G:i'): string
    {
        return $moment->setTimezone(SelectionSlots::TIMEZONE)->format($format);
    }
}
