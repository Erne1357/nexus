<?php

namespace App\Modules\Staffing\Notifications;

use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Staffing\Models\ChangeRequest;

/**
 * Base de los avisos de una solicitud de cambio (tarea 3.11): llevan a la pantalla «Solicitudes de cambio»
 * (`staffing.change-requests`) con la solicitud elegida (`?solicitud=`), en el periodo y la modalidad de la solicitud;
 * al abrirlo, la campana deja la credencial en ellos (OpenNotification). La modalidad es el alcance del aviso y el campus
 * de hoy del grupo (la solicitud no lo copia, M8) va solo como filtro de la pantalla (`parameters.campus`), como en los
 * préstamos de salón (AgreementText::parameters, MS14): si el grupo cambia de campus, quien la pidió con un nombramiento
 * del campus de antes sigue abriendo el aviso, porque la pantalla se lee en toda la modalidad (MS6). Los avisos guardados
 * con el campus como alcance los pasó a esta forma 2026_11_12_211201.
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
        $this->request->loadMissing(['group.campus' => fn ($campus) => $campus->withTrashed()]);
        [$title, $icon, $tone] = $this->content();
        $campus = $this->request->group->campus;

        return new NotificationMessage(
            title: $title,
            body: "Grupo {$this->request->group->code} · {$this->request->kind->label()}",
            icon: $icon,
            route: $this->route(),
            parameters: ['solicitud' => (string) $this->request->getKey(), ...($campus === null ? [] : ['campus' => $campus->code])],
            termId: $this->request->term_id,
            tone: $tone,
            modalityId: $this->request->modality_id,
        );
    }

    protected function route(): string
    {
        return 'staffing.change-requests';
    }
}
