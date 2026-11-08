<?php

namespace App\Modules\Staffing\Queries;

/**
 * Un docente en el tablero de carga por docente: sus horas de `teacher_term_loads`, su plaza, lo asignado por campus,
 * sus grupos y sus sesiones de la semana para el drawer.
 */
final readonly class TeacherLoadRow
{
    public const string OVER = 'over';

    public const string NONE = 'none';

    public const string PART = 'part';

    public const string FULL = 'full';

    public const string HONORARIOS = 'hon';

    /**
     * @param  list<string>  $positionKinds  valores de PositionKind de las plazas vigentes
     * @param  list<string>  $positionLabels  TC, 3/4, MT, Asig. 12 h, Hon., Apoyo
     * @param  array<string, int>  $hoursByCampus  horas asignadas por clave de campus
     * @param  list<array{code: string, subject: string, subject_code: string, campus: string, hours: int}>  $groups
     * @param  list<array{day: int, from: int, to: int, campus: string, code: string, subject: string}>  $sessions  minutos desde medianoche
     */
    public function __construct(
        public string $teacherId,
        public string $name,
        public string $rfc,
        public int $baseHours,
        public int $externalHours,
        public int $assignedHours,
        public int $limitHours,
        public int $remainingHours,
        public int $groupsCount,
        public array $positionKinds,
        public array $positionLabels,
        public bool $isHonorarios,
        public array $hoursByCampus,
        public array $groups,
        public array $sessions,
    ) {}

    /**
     * La sección del tablero: exceden (HC-08), honorarios, sin carga, carga completa o con horas libres.
     */
    public function status(): string
    {
        return match (true) {
            $this->remainingHours < 0 => self::OVER,
            $this->isHonorarios => self::HONORARIOS,
            $this->assignedHours === 0 => self::NONE,
            $this->remainingHours === 0 => self::FULL,
            default => self::PART,
        };
    }

    /**
     * Las horas de más sobre su tope, o 0.
     */
    public function excessHours(): int
    {
        return max(0, -$this->remainingHours);
    }
}
