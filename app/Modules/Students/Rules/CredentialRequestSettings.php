<?php

namespace App\Modules\Students\Rules;

use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Settings\SettingDefinition;
use App\Modules\Core\Settings\SettingType;
use App\Modules\Students\Enums\StudentsPermission;

/**
 * Los plazos entre solicitudes de credencial (CO-07, C4): `students.credential_reposition_days` y
 * `students.credential_photo_days`, en días contados desde la última solicitud entregada de ese tipo. 0 es sin límite,
 * el valor inicial hasta que Comunicación y Difusión lo fije (CO-S1, supuesto por confirmar). Un valor que falta o no es
 * válido usa el 0. Los cambia Comunicación y el admin sin despliegue.
 */
final readonly class CredentialRequestSettings
{
    public const string REPOSITION_DAYS_SETTING = 'students.credential_reposition_days';

    public const string PHOTO_DAYS_SETTING = 'students.credential_photo_days';

    public function __construct(public int $repositionDays = 0, public int $photoDays = 0) {}

    public static function fromSettings(): self
    {
        return self::fromValues(Setting::query()
            ->where('scope', SettingScope::Global)
            ->whereNull('scope_id')
            ->whereIn('key', [self::REPOSITION_DAYS_SETTING, self::PHOTO_DAYS_SETTING])
            ->pluck('value', 'key')
            ->all());
    }

    /**
     * @param  array<string, mixed>  $values  llave del parámetro => valor guardado
     */
    public static function fromValues(array $values): self
    {
        return new self(
            repositionDays: self::daysOr($values[self::REPOSITION_DAYS_SETTING] ?? null),
            photoDays: self::daysOr($values[self::PHOTO_DAYS_SETTING] ?? null),
        );
    }

    /**
     * @return list<SettingDefinition>
     */
    public static function definitions(): array
    {
        $common = ['students', 'Alumnos'];
        $owner = ['permission' => StudentsPermission::CredentialRequestManage];

        return [
            new SettingDefinition(self::REPOSITION_DAYS_SETTING, ...$common, ...$owner, label: 'Plazo para pedir otra reposición',
                help: 'Cuántos días deben pasar desde la última reposición entregada para que el alumno pida otra. 0 es sin límite.',
                type: SettingType::Integer, default: 0, rules: ['min:0', 'max:3650'], unit: 'días'),
            new SettingDefinition(self::PHOTO_DAYS_SETTING, ...$common, ...$owner, label: 'Plazo para pedir otra foto',
                help: 'Cuántos días deben pasar desde la última foto entregada para que el alumno pida otra. 0 es sin límite.',
                type: SettingType::Integer, default: 0, rules: ['min:0', 'max:3650'], unit: 'días'),
        ];
    }

    private static function daysOr(mixed $value): int
    {
        return is_int($value) && $value >= 0 && $value <= 3650 ? $value : 0;
    }
}
