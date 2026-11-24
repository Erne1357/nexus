<?php

namespace App\Modules\Staffing\Notifications;

use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;
use Carbon\CarbonImmutable;

/**
 * Llega al docente 10 minutos antes de su turno de selección y otra vez cuando empieza (diseño 6.9, supuesto por
 * confirmar). Lleva a «Oferta», donde elige.
 */
final class SelectionTurnStarting extends NexusNotification
{
    public function __construct(
        private readonly string $termId,
        private readonly string $modalityId,
        private readonly CarbonImmutable $startsAt,
        private readonly bool $started,
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
            title: $this->started ? 'Empieza tu turno de selección' : 'Tu turno empieza en 10 minutos',
            body: 'Tu turno es a las '.$this->startsAt->setTimezone('America/Ciudad_Juarez')->format('H:i').'.',
            icon: 'o-clock',
            route: 'staffing.offer',
            termId: $this->termId,
            tone: $this->started ? 'success' : 'info',
            modalityId: $this->modalityId,
        );
    }
}
