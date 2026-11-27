<?php

namespace App\Modules\AcademicLoad\Rules;

use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Settings\SettingDefinition;
use App\Modules\Core\Settings\SettingType;

/**
 * Los parámetros de los acuerdos entre departamentos (tarea 1.53, panel de configuración de la tarea 1.22):
 *
 * - `academic_load.room_loans_enabled`: si se pueden pedir préstamos de salón. Apagado por omisión «de momento», porque la
 *   prueba de la selección es con Sistemas y solo ofrece salones confirmados de su departamento (confirmado el 26 de
 *   noviembre de 2026). Apagado, RequestRoomLoan rechaza, el editor de horarios no ofrece los salones de otro departamento
 *   y los préstamos que ya existen se siguen decidiendo y cancelando.
 *
 * Un valor que falta o no es sí o no usa el de omisión. La definición la registra AcademicLoadServiceProvider en el
 * catálogo de Core (CF-S7).
 */
final class AgreementSettings
{
    public const string ROOM_LOANS_SETTING = 'academic_load.room_loans_enabled';

    public const bool DEFAULT_ROOM_LOANS = false;

    public const string ROOM_LOANS_UNAVAILABLE = 'Los préstamos de salón no están disponibles por ahora.';

    public static function roomLoansEnabled(): bool
    {
        $value = Setting::query()
            ->where('scope', SettingScope::Global)->whereNull('scope_id')->where('key', self::ROOM_LOANS_SETTING)
            ->first()?->value;

        return is_bool($value) ? $value : self::DEFAULT_ROOM_LOANS;
    }

    /**
     * @return list<SettingDefinition>
     */
    public static function definitions(): array
    {
        return [
            new SettingDefinition(
                self::ROOM_LOANS_SETTING,
                'academic_load',
                'Acuerdos entre departamentos',
                label: 'Permitir préstamos de salón',
                help: 'Apagado, un departamento no puede pedir prestado un salón de otro: el editor de horarios solo ofrece salones propios o sin dueño y pedir un préstamo se rechaza. Los préstamos que ya existen se siguen aceptando, rechazando y cancelando.',
                type: SettingType::Boolean,
                default: self::DEFAULT_ROOM_LOANS,
            ),
        ];
    }
}
