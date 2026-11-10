<?php

namespace App\Modules\AcademicLoad\Notifications;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;
use Illuminate\Support\Str;

/**
 * Base de los avisos del cierre de una carga (tarea 4.2): todos llevan a la pantalla de cierre del departamento
 * (`staffing.closing`, la pantalla de cierre de la tarea 4.1; mientras no exista, abrir el aviso dice que esa pantalla no está
 * disponible, NexusNotification::unavailableReason), en el periodo y el campus de la carga. Cada aviso define su
 * título, ícono y tono.
 */
abstract class LoadClosingNotification extends NexusNotification
{
    public function __construct(
        protected readonly AcademicLoad $load,
        protected readonly Department $department,
        protected readonly ?string $comment = null,
    ) {}

    abstract protected function title(): string;

    abstract protected function icon(): string;

    protected function tone(): string
    {
        return 'info';
    }

    public function message(object $notifiable): NotificationMessage
    {
        return new NotificationMessage(
            title: $this->title(),
            body: Str::limit($this->comment ?? '', 160),
            icon: $this->icon(),
            route: 'staffing.closing',
            parameters: ['departamento' => $this->department->getKey()],
            termId: $this->load->term_id,
            campusId: $this->load->campus_id,
            tone: $this->tone(),
        );
    }
}
