<?php

namespace App\Modules\Staffing\Notifications;

use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Staffing\Models\ChangeRequest;

/**
 * Base de los avisos de una solicitud de cambio (tarea 3.11): llevan a la pantalla «Solicitudes de cambio»
 * (`staffing.change-requests`) con la solicitud elegida (`?solicitud=`), en el periodo y campus del grupo; al abrirlo, la
 * campana deja la credencial en ellos (OpenNotification).
 */
abstract class ChangeRequestNotification extends NexusNotification
{
    public function __construct(protected readonly ChangeRequest $request) {}

    /**
     * @return array{0: string, 1: string, 2: string} título, icono y tono
     */
    abstract protected function content(): array;

    public function message(object $notifiable): NotificationMessage
    {
        $this->request->loadMissing('group');
        [$title, $icon, $tone] = $this->content();

        return new NotificationMessage(
            title: $title,
            body: "Grupo {$this->request->group->code} · {$this->request->kind->label()}",
            icon: $icon,
            route: $this->route(),
            parameters: ['solicitud' => (string) $this->request->getKey()],
            termId: $this->request->term_id,
            campusId: $this->request->campus_id,
            tone: $tone,
            modalityId: $this->request->modality_id,
        );
    }

    protected function route(): string
    {
        return 'staffing.change-requests';
    }
}
