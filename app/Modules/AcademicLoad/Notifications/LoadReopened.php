<?php

namespace App\Modules\AcademicLoad\Notifications;

/**
 * «Subdirección reabrió la carga de <depto>»: al jefe, con el motivo, cuando la carga vuelve a ajustes desde cerrada, aprobada o publicada.
 */
final class LoadReopened extends LoadClosingNotification
{
    public static function typeKey(): string
    {
        return 'load-reopened';
    }

    public static function typeLabel(): string
    {
        return 'Carga reabierta';
    }

    protected function title(): string
    {
        return "Subdirección reabrió la carga de {$this->department->name}";
    }

    protected function icon(): string
    {
        return 'o-arrow-uturn-left';
    }

    protected function tone(): string
    {
        return 'warning';
    }
}
