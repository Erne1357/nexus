<?php

namespace App\Modules\Demand\Enums;

/**
 * Con qué datos se calculó una fila de `demand_forecasts` (tarea 5.1): con las tasas históricas de la materia en su
 * campus, con la tasa promedio de su departamento porque la materia no tiene historia (tarea 5.2), sin ninguna tasa
 * (los esperados son los elegibles), o en primer semestre, donde además entra el nuevo ingreso histórico (confirmado el
 * 16 de noviembre de 2026). La versión de la fórmula va aparte, en `formula_version`.
 */
enum ForecastMethod: string
{
    case Formula = 'formula';
    case DepartmentFallback = 'department_fallback';
    case NoHistory = 'no_history';
    case FirstSemester = 'first_semester';

    public function label(): string
    {
        return match ($this) {
            self::Formula => 'Historia de la materia',
            self::DepartmentFallback => 'Promedio del departamento',
            self::NoHistory => 'Sin historia',
            self::FirstSemester => 'Primer semestre',
        };
    }
}
