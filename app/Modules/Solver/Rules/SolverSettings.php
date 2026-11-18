<?php

namespace App\Modules\Solver\Rules;

use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Settings\SettingDefinition;
use App\Modules\Core\Settings\SettingType;
use App\Modules\Solver\Enums\RunScope;

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

    public function __construct(
        public int $departmentSeconds = self::DEFAULT_DEPARTMENT_SECONDS,
        public int $careerSeconds = self::DEFAULT_CAREER_SECONDS,
    ) {}

    public static function fromSettings(): self
    {
        return self::fromValue(Setting::query()
            ->where('scope', SettingScope::Global)
            ->whereNull('scope_id')
            ->where('key', self::TIME_LIMITS_SETTING)
            ->value('value'));
    }

    public static function fromValue(mixed $value): self
    {
        $value = is_array($value) ? $value : [];
        $positive = fn (mixed $seconds, int $fallback): int => is_int($seconds) && $seconds > 0 ? $seconds : $fallback;

        return new self(
            $positive($value['department'] ?? null, self::DEFAULT_DEPARTMENT_SECONDS),
            $positive($value['career'] ?? null, self::DEFAULT_CAREER_SECONDS),
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
                fields: ['department' => 'Carga de un departamento', 'career' => 'Exploración de una carrera'], unit: 'segundos'),
        ];
    }
}
