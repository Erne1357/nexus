<?php

namespace App\Modules\Core\Listeners;

use App\Modules\Core\Authorization\AcademicLoadVisibility;
use App\Modules\Core\Notifications\AcademicLoadNotice;
use Illuminate\Notifications\Events\NotificationSending;

/**
 * MN-05 (supuesto por confirmar): mientras la carga académica está oculta, sus avisos (AcademicLoadNotice) no salen por
 * correo, a nadie, porque su enlace llevaría a un 403 o un 404. Solo se cancela el canal `mail`: la campana (`database`)
 * se guarda igual y la muestra al volver a mostrar la carga. Se decide al mandar, también al salir de la cola.
 */
final class HoldAcademicLoadMail
{
    public function __construct(private readonly AcademicLoadVisibility $visibility) {}

    /**
     * Falso cancela ese canal para ese destinatario (NotificationSender lo pregunta con `until`).
     */
    public function handle(NotificationSending $event): ?bool
    {
        if ($event->channel === 'mail' && $event->notification instanceof AcademicLoadNotice && $this->visibility->isHidden()) {
            return false;
        }

        return null;
    }
}
