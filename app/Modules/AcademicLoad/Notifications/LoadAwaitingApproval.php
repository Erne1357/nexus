<?php

namespace App\Modules\AcademicLoad\Notifications;

/**
 * «La carga de <depto> espera tu visto bueno»: a Subdirección cuando un campus que exige el visto bueno recibe una carga cerrada.
 */
final class LoadAwaitingApproval extends LoadClosingNotification
{
    public static function typeKey(): string
    {
        return 'load-awaiting-approval';
    }

    public static function typeLabel(): string
    {
        return 'Carga espera visto bueno';
    }

    protected function title(): string
    {
        return "La carga de {$this->department->name} espera tu visto bueno";
    }

    protected function icon(): string
    {
        return 'o-clipboard-document-check';
    }
}
