<?php

namespace App\Modules\Demand\Rules;

use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Settings\SettingDefinition;
use App\Modules\Core\Settings\SettingType;

/**
 * Los parámetros de la proyección de demanda, globales para todo el Tec y editables por Subdirección y el admin sin
 * despliegue (confirmado el 10 de noviembre de 2026; tarea 5.8):
 *
 * - `demand.history_terms`: cuántos periodos del mismo tipo miran las tasas, 4 (confirmado el 10 de noviembre de 2026).
 * - `demand.target_group_size`: el tamaño objetivo del grupo, normal 35 y con laboratorio 25 (SuggestedGroups; bastan
 *   los dos, confirmado el 16 de noviembre de 2026).
 * - `demand.min_group_size`: menos alumnos esperados por grupo marca la fila de la propuesta, 10 (ProposalAnomalies;
 *   supuesto por confirmar).
 * - `demand.highlight_threshold`: el porcentaje del sugerido a partir del cual una diferencia con lo capturado se
 *   resalta, 20 (supuesto por confirmar; isLargeDifference()).
 *
 * Un valor que falta o no es válido usa el de omisión. Puro: solo fromSettings() lee la base, en una consulta. Las
 * definiciones las registra DemandServiceProvider en el catálogo de Core (CF-S7), y la pantalla «Parámetros» las pinta.
 */
final readonly class DemandSettings
{
    public const string HISTORY_TERMS = 'demand.history_terms';

    public const string TARGET_GROUP_SIZE = SuggestedGroups::SETTING;

    public const string MIN_GROUP_SIZE = 'demand.min_group_size';

    public const string HIGHLIGHT_THRESHOLD = 'demand.highlight_threshold';

    public const int DEFAULT_HISTORY_TERMS = 4;

    public const int DEFAULT_MIN_GROUP_SIZE = 10;

    public const int DEFAULT_HIGHLIGHT_THRESHOLD = 20;

    public function __construct(
        public int $historyTerms = self::DEFAULT_HISTORY_TERMS,
        public SuggestedGroups $groupSizes = new SuggestedGroups,
        public int $minGroupSize = self::DEFAULT_MIN_GROUP_SIZE,
        public int $highlightThreshold = self::DEFAULT_HIGHLIGHT_THRESHOLD,
    ) {}

    public static function fromSettings(): self
    {
        return self::fromValues(Setting::query()
            ->where('scope', SettingScope::Global)
            ->whereNull('scope_id')
            ->whereIn('key', [self::HISTORY_TERMS, self::TARGET_GROUP_SIZE, self::MIN_GROUP_SIZE, self::HIGHLIGHT_THRESHOLD])
            ->pluck('value', 'key')
            ->all());
    }

    /**
     * @param  array<string, mixed>  $values  llave del parámetro => valor guardado
     */
    public static function fromValues(array $values): self
    {
        return new self(
            historyTerms: self::positiveOr($values[self::HISTORY_TERMS] ?? null, self::DEFAULT_HISTORY_TERMS),
            groupSizes: SuggestedGroups::fromValue($values[self::TARGET_GROUP_SIZE] ?? null),
            minGroupSize: self::positiveOr($values[self::MIN_GROUP_SIZE] ?? null, self::DEFAULT_MIN_GROUP_SIZE),
            highlightThreshold: self::positiveOr($values[self::HIGHLIGHT_THRESHOLD] ?? null, self::DEFAULT_HIGHLIGHT_THRESHOLD),
        );
    }

    /**
     * Si lo capturado se aleja de lo sugerido lo bastante para resaltarlo (5.6 y 5.7): la diferencia es el umbral o más
     * del sugerido, o no se sugería nada y se capturó algo.
     */
    public function isLargeDifference(int $suggested, int $captured): bool
    {
        if ($suggested === 0) {
            return $captured > 0;
        }

        return abs($captured - $suggested) * 100 >= $this->highlightThreshold * $suggested;
    }

    /**
     * Las definiciones de los cuatro parámetros para el catálogo de Core (CF-S7), con los valores de omisión que usa
     * fromValues().
     *
     * @return list<SettingDefinition>
     */
    public static function definitions(): array
    {
        $module = ['demand', 'Proyección de demanda'];

        return [
            new SettingDefinition(self::HISTORY_TERMS, ...$module, label: 'Semestres de historia',
                help: 'Cuántos semestres anteriores del mismo tipo (Ene-Jun con Ene-Jun, Ago-Dic con Ago-Dic) miran las tasas de no inscripción y de reprobación de cada materia.',
                type: SettingType::Integer, default: self::DEFAULT_HISTORY_TERMS, rules: ['min:1', 'max:12'], unit: 'semestres'),
            new SettingDefinition(self::TARGET_GROUP_SIZE, ...$module, label: 'Alumnos por grupo que se buscan',
                help: 'El tamaño de grupo con el que la proyección y la propuesta de carrera sugieren cuántos grupos abrir: el general y el de las materias con laboratorio. Cada tamaño es un entero de uno en adelante.',
                type: SettingType::IntegerTable, default: ['default' => SuggestedGroups::DEFAULT_SIZE, 'lab' => SuggestedGroups::LAB_SIZE], rules: ['min:1', 'max:100'],
                fields: ['default' => 'Materias sin laboratorio', 'lab' => 'Materias con laboratorio'], unit: 'alumnos'),
            new SettingDefinition(self::MIN_GROUP_SIZE, ...$module, label: 'Mínimo de alumnos por grupo',
                help: 'Si la propuesta de una materia deja menos alumnos esperados por grupo que este número, la fila se marca como rara. No bloquea el envío.',
                type: SettingType::Integer, default: self::DEFAULT_MIN_GROUP_SIZE, rules: ['min:1', 'max:100'], unit: 'alumnos'),
            new SettingDefinition(self::HIGHLIGHT_THRESHOLD, ...$module, label: 'Diferencia que se resalta',
                help: 'Si lo que captura el coordinador se aleja de lo que sugiere Nexus en este porcentaje o más, la diferencia se resalta en la propuesta y en el tablero del departamento.',
                type: SettingType::Integer, default: self::DEFAULT_HIGHLIGHT_THRESHOLD, rules: ['min:1', 'max:100'], unit: '%'),
        ];
    }

    private static function positiveOr(mixed $value, int $fallback): int
    {
        return is_int($value) && $value > 0 ? $value : $fallback;
    }
}
