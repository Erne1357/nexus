<?php

namespace App\Modules\Core\Enums;

/**
 * Cuánto siembra la demo (`php artisan demo:seed --volumen=`): `demo` es Campus II en pequeño, el conjunto de los
 * browser tests; `masivo` son Campus I y Campus II con volumen para revisar pantallas y rendimiento. Cada módulo decide
 * sus tamaños según el volumen, con los mismos números que `DemoSeeder` y `MassiveSeeder`.
 */
enum DemoVolume: string
{
    case Demo = 'demo';
    case Massive = 'masivo';

    public function label(): string
    {
        return $this->value;
    }

    /**
     * Los campus que siembra, por código.
     *
     * @return list<string>
     */
    public function campuses(): array
    {
        return match ($this) {
            self::Demo => ['C2'],
            self::Massive => ['C1', 'C2'],
        };
    }

    /**
     * Las modalidades de su carga, por clave: Presencial, la de los grupos de sus campus (M1, M4). El campus es solo el
     * lugar (MS3) y no dice su modalidad, así que el volumen la nombra como nombra sus campus.
     *
     * @return list<string>
     */
    public function modalities(): array
    {
        return ['PRES'];
    }
}
