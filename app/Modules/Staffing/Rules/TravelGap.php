<?php

namespace App\Modules\Staffing\Rules;

use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Setting;

/**
 * SC-09 (reglas-de-negocio.md) como lo muestra «Mi carga»: entre dos sesiones seguidas del mismo día en campus
 * distintos, el docente tiene al menos `staffing.min_campus_travel_minutes` (60 por omisión) libres; con menos
 * (de 0 en adelante), el traslado es un aviso. Nunca bloquea. Si las sesiones se empalman no es un aviso de traslado:
 * es un choque (HC-01). La regla del validador vive en Scheduling; esta solo arma la vista. Puro: solo fromSettings()
 * lee la base.
 */
final readonly class TravelGap
{
    public const string SETTING = 'staffing.min_campus_travel_minutes';

    public const int DEFAULT_MINUTES = 60;

    public function __construct(public int $minimumMinutes = self::DEFAULT_MINUTES) {}

    public static function fromSettings(): self
    {
        return self::fromValue(Setting::query()
            ->where('scope', SettingScope::Global)
            ->whereNull('scope_id')
            ->where('key', self::SETTING)
            ->value('value'));
    }

    /**
     * Un valor que falta o no es un entero de 0 en adelante usa el de por omisión.
     */
    public static function fromValue(mixed $value): self
    {
        return new self(is_int($value) && $value >= 0 ? $value : self::DEFAULT_MINUTES);
    }

    /**
     * El traslado de una sesión que termina en `$fromEnd` a la que empieza en `$toStart` (minutos del día): los minutos
     * libres y si son menos del mínimo. En el mismo campus no hay traslado (null).
     *
     * @return array{free_minutes: int, short: bool}|null
     */
    public function between(string $fromCampus, int $fromEnd, string $toCampus, int $toStart): ?array
    {
        if ($fromCampus === $toCampus) {
            return null;
        }

        $free = $toStart - $fromEnd;

        return ['free_minutes' => $free, 'short' => $free >= 0 && $free < $this->minimumMinutes];
    }
}
