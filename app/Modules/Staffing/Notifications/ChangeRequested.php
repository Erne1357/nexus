<?php

namespace App\Modules\Staffing\Notifications;

/**
 * Llega a los jefes del departamento del grupo cuando alguien pide un cambio.
 */
final class ChangeRequested extends ChangeRequestNotification
{
    public static function typeKey(): string
    {
        return 'change-requested';
    }

    public static function typeLabel(): string
    {
        return 'Cambio solicitado';
    }

    protected function content(): array
    {
        return ['Solicitud de cambio de '.mb_strtolower($this->request->kind->label()), 'o-arrow-path', 'info'];
    }
}
