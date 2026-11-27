<?php

namespace App\Modules\Staffing\Rules;

use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Setting;
use App\Modules\Faculty\Rules\TeachingHours;

/**
 * PL-03 y HC-22. El tope de horas de quien tiene plaza administrativa (`staffing.administrative_max_hours`, por omisión 42): clases,
 * horas externas y horas administrativas no pueden pasar de él (HC-22, límite duro). Lo leen las pantallas de la
 * selección; las reglas lo leen de la foto del periodo (TeacherHourLimit).
 */
final class AdministrativeCap
{
    public const string SETTING = 'staffing.administrative_max_hours';

    public static function current(): int
    {
        $value = Setting::query()->where('scope', SettingScope::Global)->whereNull('scope_id')->where('key', self::SETTING)->value('value');

        return is_int($value) && $value >= 1 ? $value : TeachingHours::ADMINISTRATIVE_MAX;
    }
}
