<?php

namespace App\Modules\Staffing\Notifications;

/**
 * Llega a quien pidió el cambio cuando el jefe lo aprueba.
 */
final class ChangeRequestApproved extends ChangeRequestNotification
{
    public static function typeKey(): string
    {
        return 'change-request-approved';
    }

    public static function typeLabel(): string
    {
        return 'Cambio aprobado';
    }

    protected function content(): array
    {
        return ['Tu solicitud de cambio fue aprobada', 'o-check-circle', 'success'];
    }
}
