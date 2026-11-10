<?php

namespace App\Modules\Staffing\Rules;

use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Setting;

/**
 * Pesos del puntaje del ranking docente (reglas-de-negocio.md, «Ranking docente (desempate)», confirmado el 7 de
 * noviembre de 2026): 1 por grupo impartido, 3 por perfil preferente, 1 por apto y 2 por punto de evaluación. Salen
 * del parámetro global `staffing.weights` (objeto json con `per_group_taught`, `preferente`, `apto` y
 * `evaluation`); cada clave que falta o no es un número mayor o igual a cero usa su valor por omisión. Puro: solo
 * fromSettings() lee la base.
 */
final readonly class RankingWeights
{
    public const string SETTING = 'staffing.weights';

    public function __construct(
        public float $perGroupTaught = 1.0,
        public float $preferente = 3.0,
        public float $apto = 1.0,
        public float $evaluation = 2.0,
    ) {}

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
        $value = is_array($value) ? $value : [];
        $default = new self;

        return new self(
            perGroupTaught: self::weight($value['per_group_taught'] ?? null, $default->perGroupTaught),
            preferente: self::weight($value['preferente'] ?? null, $default->preferente),
            apto: self::weight($value['apto'] ?? null, $default->apto),
            evaluation: self::weight($value['evaluation'] ?? null, $default->evaluation),
        );
    }

    private static function weight(mixed $value, float $default): float
    {
        return (is_int($value) || is_float($value)) && $value >= 0 ? (float) $value : $default;
    }
}
