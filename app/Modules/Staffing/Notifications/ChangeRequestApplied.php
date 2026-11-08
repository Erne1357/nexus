<?php

namespace App\Modules\Staffing\Notifications;

/**
 * Llega a quien pidió el cambio cuando ya se aplicó en el horario.
 */
final class ChangeRequestApplied extends ChangeRequestNotification
{
    public static function typeKey(): string
    {
        return 'change-request-applied';
    }

    public static function typeLabel(): string
    {
        return 'Cambio aplicado';
    }

    protected function content(): array
    {
        return ['Tu cambio ya se aplicó', 'o-check-badge', 'success'];
    }
}
