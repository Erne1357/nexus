<?php

namespace App\Modules\AcademicLoad\Notifications;

/**
 * «Subdirección dio el visto bueno a la carga de <depto>»: al jefe, para que la publique.
 */
final class LoadApproved extends LoadClosingNotification
{
    public static function typeKey(): string
    {
        return 'load-approved';
    }

    public static function typeLabel(): string
    {
        return 'Carga aprobada';
    }

    protected function title(): string
    {
        return "Subdirección dio el visto bueno a la carga de {$this->department->name}";
    }

    protected function icon(): string
    {
        return 'o-check-badge';
    }

    protected function tone(): string
    {
        return 'success';
    }
}
