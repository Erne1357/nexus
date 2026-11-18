<?php

namespace App\Modules\AcademicLoad\Notifications;

/**
 * «Ya puedes re-analizar la carga de <depto>»: al jefe, cuando llegaron las calificaciones del semestre actual y la
 * proyección de demanda ya las refleja (RA-01 y RA-02, tarea 8.1). Lleva a la pantalla de cierre del departamento.
 */
final class ReanalysisAvailable extends LoadClosingNotification
{
    public static function typeKey(): string
    {
        return 'reanalysis-available';
    }

    public static function typeLabel(): string
    {
        return 'Re-análisis disponible';
    }

    protected function title(): string
    {
        return "Ya puedes re-analizar la carga de {$this->department->name}";
    }

    protected function icon(): string
    {
        return 'o-arrow-path';
    }

    /**
     * El aviso lleva a la pantalla del re-análisis, donde el jefe ve el avance de las calificaciones y lo inicia.
     */
    protected function route(): string
    {
        return 'scheduling.reanalysis';
    }

    protected function tone(): string
    {
        return 'warning';
    }
}
