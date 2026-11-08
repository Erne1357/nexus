<?php

namespace App\Modules\Staffing\Notifications;

/**
 * Llega a quien pidió el cambio cuando el jefe lo rechaza; el comentario va en la solicitud.
 */
final class ChangeRequestRejected extends ChangeRequestNotification
{
    public static function typeKey(): string
    {
        return 'change-request-rejected';
    }

    public static function typeLabel(): string
    {
        return 'Cambio rechazado';
    }

    protected function content(): array
    {
        return ['Tu solicitud de cambio fue rechazada', 'o-x-circle', 'warning'];
    }
}
