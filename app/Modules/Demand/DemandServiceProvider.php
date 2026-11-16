<?php

namespace App\Modules\Demand;

use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Settings\SettingDefinition;
use App\Modules\Demand\Rules\DemandSettings;

/**
 * Módulo Demand (fase 5, R2): la proyección de cuántos alumnos van a necesitar cada materia el siguiente periodo y
 * cuántos grupos conviene abrir (docs/plan/fase-5-demanda.md). Depende de Students y de lo que está a su izquierda;
 * AcademicLoad lo lee para precargar la propuesta y armar el tablero del departamento. Demand calcula; lo que compara
 * con las propuestas o con lo publicado vive en AcademicLoad.
 */
final class DemandServiceProvider extends ModuleServiceProvider
{
    /**
     * Los parámetros de la proyección, que también leen la propuesta de carrera y la bandeja (CF-S7).
     *
     * @return list<SettingDefinition>
     */
    public function settings(): array
    {
        return DemandSettings::definitions();
    }
}
