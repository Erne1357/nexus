<?php

namespace App\Modules\AcademicLoad\Rules;

use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Setting;

/**
 * Grupos sugeridos para una materia de la propuesta de carrera (reglas-de-negocio.md, "Propuesta de carrera",
 * confirmado el 2 de noviembre de 2026): `ceil(esperados / tamaño objetivo)`, con el tamaño de una materia con
 * laboratorio (`needs_lab`) o el general. Los tamaños son los del parámetro global `demand.target_group_size`
 * (`{"default": 35, "lab": 25}`, "Elegibilidad y demanda"); sin él, o con un valor que no es un entero mayor
 * que cero, 35 y 25 (supuesto por confirmar).
 *
 * El cálculo es puro; solo fromSettings() lee la base, una vez por propuesta. En R2, Demand sugiere los grupos
 * con su propio análisis.
 */
final readonly class SuggestedGroups
{
    public const string SETTING = 'demand.target_group_size';

    public const int DEFAULT_SIZE = 35;

    public const int LAB_SIZE = 25;

    public function __construct(
        public int $defaultSize = self::DEFAULT_SIZE,
        public int $labSize = self::LAB_SIZE,
    ) {}

    /**
     * Los tamaños del parámetro global, o los de omisión si no está.
     */
    public static function fromSettings(): self
    {
        return self::fromValue(Setting::query()
            ->where('scope', SettingScope::Global)
            ->whereNull('scope_id')
            ->where('key', self::SETTING)
            ->value('value'));
    }

    /**
     * Los tamaños de un valor del parámetro: cada uno que no sea un entero mayor que cero toma el de omisión.
     */
    public static function fromValue(mixed $value): self
    {
        $value = is_array($value) ? $value : [];

        return new self(
            self::sizeOr($value['default'] ?? null, self::DEFAULT_SIZE),
            self::sizeOr($value['lab'] ?? null, self::LAB_SIZE),
        );
    }

    public function for(int $expectedStudents, bool $needsLab): int
    {
        if ($expectedStudents <= 0) {
            return 0;
        }

        $size = $needsLab ? $this->labSize : $this->defaultSize;

        return intdiv($expectedStudents + $size - 1, $size);
    }

    private static function sizeOr(mixed $size, int $fallback): int
    {
        return is_int($size) && $size > 0 ? $size : $fallback;
    }
}
