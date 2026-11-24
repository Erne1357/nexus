<?php

namespace App\Modules\Staffing\Notifications;

use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Models\SelectionEntry;
use App\Modules\Staffing\Rules\SelectionSlots;

/**
 * Llega al docente cuando se publica la selección de su departamento: le dice el día y la hora de su turno (SEL-11).
 */
final class SelectionTurnPublished extends NexusNotification
{
    public function __construct(private readonly Selection $selection, private readonly SelectionEntry $entry) {}

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
        $start = $this->entry->slot_starts_at->setTimezone(SelectionSlots::TIMEZONE)->locale('es');
        $end = $this->entry->slot_ends_at->setTimezone(SelectionSlots::TIMEZONE);

        return new NotificationMessage(
            title: 'Ya puedes consultar tu turno de selección',
            body: 'Tu turno de selección es el '.$start->translatedFormat('l j \d\e F').' de '.$start->format('H:i').' a '.$end->format('H:i'),
            icon: 'o-clock',
            route: 'staffing.offer',
            termId: $this->selection->term_id,
            campusId: $this->selection->campus_id,
            tone: 'info',
            modalityId: $this->selection->modality_id,
        );
    }
}
