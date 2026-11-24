<?php

namespace App\Modules\Staffing\Notifications;

use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;

/**
 * Llega al jefe del departamento y al JPD cuando termina la ronda extra con docentes sin cerrar (diseño 6.3 y 6.9):
 * lleva la lista de pendientes para que les asignen a mano.
 */
final class SelectionFinishedWithPending extends NexusNotification
{
    /**
     * @param  list<string>  $teachers
     */
    public function __construct(
        private readonly string $termId,
        private readonly string $modalityId,
        private readonly string $department,
        private readonly array $teachers,
    ) {}

    public static function typeKey(): string
    {
        return 'selection-finished-with-pending';
    }

    public static function typeLabel(): string
    {
        return 'Selección terminada con pendientes';
    }

    public function message(object $notifiable): NotificationMessage
    {
        return new NotificationMessage(
            title: 'Terminó la selección de '.$this->department,
            body: count($this->teachers).' sin resolver: '.implode(', ', $this->teachers).'.',
            icon: 'o-exclamation-triangle',
            route: 'staffing.offer',
            termId: $this->termId,
            tone: 'warning',
            modalityId: $this->modalityId,
        );
    }
}
