<?php

namespace App\Modules\Staffing\Notifications;

use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Staffing\Rules\SelectionSlots;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Llega al docente 10 minutos antes de su turno de selección y otra vez cuando empieza (diseño 6.9, supuesto por
 * confirmar), por la campana y por correo. Lleva a «Oferta», donde elige.
 */
final class SelectionTurnStarting extends SelectionNotice
{
    public function __construct(
        private readonly string $termId,
        private readonly string $modalityId,
        private readonly CarbonImmutable $startsAt,
        private readonly bool $started,
        private readonly ?CarbonImmutable $endsAt = null,
    ) {}

    public static function typeKey(): string
    {
        return 'selection-turn-starting';
    }

    public static function typeLabel(): string
    {
        return 'Turno de selección';
    }

    public function message(object $notifiable): NotificationMessage
    {
        return new NotificationMessage(
            title: $this->title(),
            body: 'Tu turno es a las '.$this->startsAt->setTimezone(SelectionSlots::TIMEZONE)->format('H:i').'.',
            icon: 'o-clock',
            route: 'staffing.offer',
            termId: $this->termId,
            tone: $this->started ? 'success' : 'info',
            modalityId: $this->modalityId,
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $start = $this->startsAt->setTimezone(SelectionSlots::TIMEZONE)->format('G:i');
        $until = $this->endsAt === null ? '' : ' y tienes hasta las '.$this->endsAt->setTimezone(SelectionSlots::TIMEZONE)->format('G:i');

        return (new MailMessage)
            ->subject($this->title())
            ->greeting(self::greetingFor($notifiable))
            ->line($this->started
                ? "Empezó tu turno de selección: arrancó a las {$start}{$until}. Elige tus grupos y su salón en «Oferta»."
                : "Tu turno de selección empieza en 10 minutos, a las {$start}{$until}. Abre «Oferta» para tener a la mano los grupos libres.")
            ->line('Cuando tengas tus grupos, usa «Terminar mi carga».')
            ->action('Abrir Oferta', route('staffing.offer'));
    }

    private function title(): string
    {
        return $this->started ? 'Empieza tu turno de selección' : 'Tu turno empieza en 10 minutos';
    }
}
