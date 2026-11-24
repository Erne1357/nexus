<?php

namespace App\Modules\Staffing\Notifications;

use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Staffing\Rules\SelectionSlots;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Llega al docente que no cerró su turno cuando se arma la ronda extra, con su nueva hora (diseño 6.3 y 6.9), por la
 * campana y por correo.
 */
final class SelectionExtraRoundScheduled extends SelectionNotice
{
    public function __construct(
        private readonly string $termId,
        private readonly string $modalityId,
        private readonly CarbonImmutable $startsAt,
    ) {}

    public static function typeKey(): string
    {
        return 'selection-extra-round';
    }

    public static function typeLabel(): string
    {
        return 'Ronda extra de selección';
    }

    public function message(object $notifiable): NotificationMessage
    {
        return new NotificationMessage(
            title: 'Tienes un turno en la ronda extra',
            body: 'Tu nuevo turno es el '.$this->startsAt->setTimezone(SelectionSlots::TIMEZONE)->format('d/m/Y \a \l\a\s H:i').'.',
            icon: 'o-arrow-path',
            route: 'staffing.offer',
            termId: $this->termId,
            tone: 'warning',
            modalityId: $this->modalityId,
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $start = $this->startsAt->setTimezone(SelectionSlots::TIMEZONE)->locale('es');

        return (new MailMessage)
            ->subject('Tienes un turno en la ronda extra de selección')
            ->greeting(self::greetingFor($notifiable))
            ->line('Tu carga quedó incompleta en tu turno, así que vuelves a elegir en la ronda extra, en el mismo orden.')
            ->line('Tu nuevo turno es el '.$start->translatedFormat('l j \d\e F').' a las '.$start->format('G:i').'. Lo que ya elegiste sigue a tu nombre.')
            ->action('Ver mi turno en Oferta', route('staffing.offer'));
    }
}
