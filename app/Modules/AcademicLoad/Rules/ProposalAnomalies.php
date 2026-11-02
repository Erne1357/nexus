<?php

namespace App\Modules\AcademicLoad\Rules;

use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Setting;

/**
 * Lo raro de una fila de la propuesta, para marcarlo sin bloquear el envío (confirmado el 2 de noviembre de 2026;
 * reglas-de-negocio.md, «Propuesta de carrera»): más alumnos esperados que elegibles («105 esperados con 98
 * elegibles») y grupos con menos alumnos esperados por grupo que el mínimo («1 grupo para 2 alumnos»). El mínimo
 * sale del parámetro global `demand.min_group_size`, 10 si no existe o no es un entero mayor que cero (supuesto
 * por confirmar). Lo marcan la propuesta del coordinador y la bandeja del jefe. Puro: solo fromSettings() lee la
 * base.
 */
final readonly class ProposalAnomalies
{
    public const string SETTING = 'demand.min_group_size';

    public const int DEFAULT_MINIMUM = 10;

    public function __construct(public int $minimum = self::DEFAULT_MINIMUM) {}

    public static function fromSettings(): self
    {
        return self::fromValue(Setting::query()
            ->where('scope', SettingScope::Global)
            ->whereNull('scope_id')
            ->where('key', self::SETTING)
            ->value('value'));
    }

    public static function fromValue(mixed $value): self
    {
        return new self(is_int($value) && $value > 0 ? $value : self::DEFAULT_MINIMUM);
    }

    /**
     * @return list<string>
     */
    public function for(int $eligible, int $expected, int $groups): array
    {
        $anomalies = [];

        if ($expected > $eligible) {
            $anomalies[] = "{$expected} esperados con {$eligible} elegibles";
        }

        if ($groups > 0 && $expected < $groups * $this->minimum) {
            $anomalies[] = ($groups === 1 ? '1 grupo' : "{$groups} grupos").' para '.($expected === 1 ? '1 alumno' : "{$expected} alumnos");
        }

        return $anomalies;
    }
}
