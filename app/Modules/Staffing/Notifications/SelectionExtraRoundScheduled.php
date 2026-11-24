<?php

namespace App\Modules\Staffing\Notifications;

use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;
use Carbon\CarbonImmutable;

/**
 * Llega al docente que no cerró su turno cuando se arma la ronda extra, con su nueva hora (diseño 6.3 y 6.9).
 */
final class SelectionExtraRoundScheduled extends NexusNotification
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
            body: 'Tu nuevo turno es el '.$this->startsAt->setTimezone('America/Ciudad_Juarez')->format('d/m/Y \a \l\a\s H:i').'.',
            icon: 'o-arrow-path',
            route: 'staffing.offer',
            termId: $this->termId,
            tone: 'warning',
            modalityId: $this->modalityId,
        );
    }
}
