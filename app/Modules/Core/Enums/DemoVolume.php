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
}
