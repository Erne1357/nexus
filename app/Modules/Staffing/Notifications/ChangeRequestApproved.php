<?php

namespace App\Modules\Staffing\Notifications;

/**
 * Llega a quien pidió el cambio cuando el jefe lo aprueba; si lo aprobó con una alternativa del motor (CR-03), lo dice y
 * cómo queda.
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
        return [$this->chosenAlternative() === null ? 'Tu solicitud de cambio fue aprobada' : 'Tu solicitud de cambio se aprobó con otro acomodo', 'o-check-circle', 'success'];
    }
}
