<?php

namespace App\Modules\Solver\Rules;

use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Settings\SettingDefinition;
use App\Modules\Core\Settings\SettingType;
use App\Modules\Solver\Enums\RunScope;
use App\Modules\Solver\Enums\SolverPermission;

/**
 * Los parámetros del motor (tarea 6.3; docs/arquitectura/solver.md, «Límites de tiempo»): el tiempo máximo de una
 * corrida por alcance, 300 segundos por departamento y 60 por carrera (`solver.time_limits`). Un valor que falta o no es
 * un entero de 1 en adelante usa el de omisión. Puro: solo fromSettings() lee la base.
 */
final readonly class SolverSettings
{
    public const string TIME_LIMITS_SETTING = 'solver.time_limits';

    public const int DEFAULT_DEPARTMENT_SECONDS = 300;

    public const int DEFAULT_CAREER_SECONDS = 60;

    public const string SOFT_WEIGHTS_SETTING = 'solver.soft_weights';

    /**
     * Los pesos de SC-01 a SC-09 por omisión (tabla SC de docs/dominio/reglas-de-negocio.md). Las llaves del parámetro
     * son `sc_01`… porque los campos de una tabla de parámetros solo admiten minúsculas, dígitos y guion bajo; al armar
     * `SoftWeights` se leen como `SC-01`…
     *
     * @var array<string, int>
     */
    public const array DEFAULT_SOFT_WEIGHTS = ['sc_01' => 10, 'sc_02' => 6, 'sc_03' => 5, 'sc_04' => 4, 'sc_05' => 3, 'sc_06' => 2, 'sc_07' => 3, 'sc_08' => 2, 'sc_09' => 2];

    /**
     * @param  array<string, int>  $softWeights  campo (`sc_01`) => peso
     */
    public function __construct(
        public int $departmentSeconds = self::DEFAULT_DEPARTMENT_SECONDS,
        public int $careerSeconds = self::DEFAULT_CAREER_SECONDS,
        public array $softWeights = self::DEFAULT_SOFT_WEIGHTS,
    ) {}

    /**
     * Los pesos para el contrato: `SC-01` => peso.
     *
     * @return array<string, int>
     */
    public function softWeightsByRule(): array
    {
        $weights = [];

        foreach ($this->softWeights as $field => $weight) {
            $weights[strtoupper(str_replace('_', '-', $field))] = $weight;
        }

        return $weights;
    }

    public static function fromSettings(): self
    {
        $values = Setting::query()
            ->where('scope', SettingScope::Global)
            ->whereNull('scope_id')
            ->whereIn('key', [self::TIME_LIMITS_SETTING, self::SOFT_WEIGHTS_SETTING])
            ->pluck('value', 'key');

        return self::fromValue($values[self::TIME_LIMITS_SETTING] ?? null, $values[self::SOFT_WEIGHTS_SETTING] ?? null);
    }

    public static function fromValue(mixed $value, mixed $weights = null): self
    {
        $value = is_array($value) ? $value : [];
        $weights = is_array($weights) ? $weights : [];
        $positive = fn (mixed $seconds, int $fallback): int => is_int($seconds) && $seconds > 0 ? $seconds : $fallback;
        $weight = fn (mixed $given, int $fallback): int => is_int($given) && $given >= 0 && $given <= 100 ? $given : $fallback;

        $resolved = [];

        foreach (self::DEFAULT_SOFT_WEIGHTS as $field => $default) {
            $resolved[$field] = $weight($weights[$field] ?? null, $default);
        }

        return new self(
            $positive($value['department'] ?? null, self::DEFAULT_DEPARTMENT_SECONDS),
            $positive($value['career'] ?? null, self::DEFAULT_CAREER_SECONDS),
            $resolved,
        );
    }

    public function timeLimitFor(RunScope $scope): int
    {
        return $scope === RunScope::Department ? $this->departmentSeconds : $this->careerSeconds;
    }

    /**
     * @return list<SettingDefinition>
     */
    public static function definitions(): array
    {
        return [
            new SettingDefinition(self::TIME_LIMITS_SETTING, 'solver', 'Motor de horarios', label: 'Tiempo máximo de una corrida',
                help: 'Cuántos segundos busca el motor un horario antes de entregar el mejor que encontró: para la carga de un departamento y para la exploración de una carrera.',
                type: SettingType::IntegerTable, default: ['department' => self::DEFAULT_DEPARTMENT_SECONDS, 'career' => self::DEFAULT_CAREER_SECONDS], rules: ['min:1', 'max:3600'],
                fields: ['department' => 'Carga de un departamento', 'career' => 'Exploración de una carrera'], unit: 'segundos', permission: SolverPermission::SettingUpdate),
            new SettingDefinition(self::SOFT_WEIGHTS_SETTING, 'solver', 'Motor de horarios', label: 'Pesos de las reglas blandas',
                help: 'Cuánto pesa cada regla blanda (SC-01 a SC-09) en el puntaje del motor: de 0 a 100, y 0 la desactiva. Es de todo el Tec y aplica desde la siguiente corrida.',
                type: SettingType::IntegerTable, default: self::DEFAULT_SOFT_WEIGHTS, rules: ['min:0', 'max:100'],
                fields: [
                    'sc_01' => 'SC-01 · Materias del semestre en horas consecutivas',
                    'sc_02' => 'SC-02 · Sesiones del grupo en días distintos',
                    'sc_03' => 'SC-03 · Salón por defecto o sugerido',
                    'sc_04' => 'SC-04 · Docente en sus bloques preferidos',
                    'sc_05' => 'SC-05 · Sesiones compactas del docente',
                    'sc_06' => 'SC-06 · Docente sin cambio de edificio',
                    'sc_07' => 'SC-07 · Sin empalme entre semestres consecutivos',
                    'sc_08' => 'SC-08 · Honorarios en sus turnos disponibles',
                    'sc_09' => 'SC-09 · Traslado entre campus',
                ], unit: 'puntos', permission: SolverPermission::SettingUpdate, savedNotice: 'Los pesos aplican desde la siguiente corrida del motor.'),
        ];
    }
}
