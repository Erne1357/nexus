<?php

namespace App\Modules\Staffing\Rules;

use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Setting;

/**
 * Cuántos semestres miran el historial (`staffing.history_terms`, 6) y la evaluación (`staffing.evaluation_terms`,
 * 2) del ranking docente (reglas-de-negocio.md, «Ranking docente (desempate)»). Un valor que falta o no es un
 * entero de 1 en adelante usa el de por omisión. Puro: solo fromSettings() lee la base.
 */
final readonly class RankingWindowSizes
{
    public const string HISTORY_SETTING = 'staffing.history_terms';

    public const string EVALUATION_SETTING = 'staffing.evaluation_terms';

    public const int DEFAULT_HISTORY = 6;

    public const int DEFAULT_EVALUATION = 2;

    public function __construct(
        public int $history = self::DEFAULT_HISTORY,
        public int $evaluation = self::DEFAULT_EVALUATION,
    ) {}

    public static function fromSettings(): self
    {
        $read = fn (string $key): mixed => Setting::query()
            ->where('scope', SettingScope::Global)
            ->whereNull('scope_id')
            ->where('key', $key)
            ->value('value');

        return self::fromValues($read(self::HISTORY_SETTING), $read(self::EVALUATION_SETTING));
    }

    public static function fromValues(mixed $history, mixed $evaluation): self
    {
        return new self(
            is_int($history) && $history >= 1 ? $history : self::DEFAULT_HISTORY,
            is_int($evaluation) && $evaluation >= 1 ? $evaluation : self::DEFAULT_EVALUATION,
        );
    }
}
