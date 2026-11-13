<?php

namespace App\Modules\AcademicLoad\Notifications;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;
use Illuminate\Support\Str;

/**
 * Base de los avisos del cierre de una carga (tarea 4.2): todos llevan a la pantalla de cierre del departamento
 * (`staffing.closing?departamento=<id>`, «Cierre de la carga» de la tarea 4.1), en el periodo y la modalidad de la carga
 * (M1): al abrirlo, la credencial cambia a ese periodo y la pantalla se abre en esa modalidad (`?modalidad=`, MS14), con
 * los grupos de todos sus campus. Cada aviso define su título, ícono y
 * tono.
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
            tone: $this->tone(),
            modalityId: $this->load->modality_id,
        );
    }
}
