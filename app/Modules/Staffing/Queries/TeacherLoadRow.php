<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Faculty\Rules\TeachingHours;
use App\Modules\Scheduling\Rules\TeacherHourLimit;

/**
 * Un docente en el tablero de carga por docente: sus horas de `teacher_term_loads`, su plaza, lo asignado por campus,
 * sus grupos y sus sesiones de la semana para el drawer. `teachingHours`, `bandLimitHours` y `honorariosCapHours` son
 * los de HC-08 desde la tarea 1.50 (nulos en una fila calculada antes): con ellos la pantalla muestra la banda de horas
 * frente a grupo y el aviso del oficio con los mismos textos que el validador (TeacherHourLimit).
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
        public ?int $teachingHours = null,
        public ?int $bandLimitHours = null,
        public ?int $honorariosCapHours = null,
    ) {}

    /**
     * La sección del tablero: exceden (HC-08: pasan su banda sin que su casilla lo cubra, con el oficio pendiente),
     * honorarios, sin carga, carga completa o con horas libres.
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

    /**
     * «22 h (20 a 24)», o «12 h (tope de honorarios)»; null en una fila calculada antes de la tarea 1.50.
     */
    public function bandLabel(): ?string
    {
        return $this->teachingHours === null ? null : TeacherHourLimit::bandLabel($this->hourLimit());
    }

    /**
     * El aviso de HC-08 sin el nombre (TeacherHourLimit::notice), o null si no pasa su banda o la fila es vieja.
     */
    public function hourNotice(): ?string
    {
        return $this->teachingHours === null || $this->bandLimitHours === null ? null : TeacherHourLimit::notice($this->hourLimit());
    }

    /**
     * Lo que TeacherHourLimit necesita para la banda y el aviso, desde la fila: pasa su banda si lleva más que su tope;
     * la casilla lo cubre si con eso no rebasa su límite (que ya es lo que autorizó, SEL-12).
     *
     * @return array{teaching_hours: int, band_from: int, band_to: int, honorarios_cap: ?int, assigned_hours: int, external_hours: int, over_band: bool, accepted: bool}
     */
    private function hourLimit(): array
    {
        $teaching = (int) $this->teachingHours;
        $overBand = $this->bandLimitHours !== null && $this->assignedHours + $this->externalHours > $this->bandLimitHours;

        return [
            'teaching_hours' => $teaching,
            'band_from' => max(0, $teaching - TeachingHours::MARGIN),
            'band_to' => $teaching + TeachingHours::MARGIN,
            'honorarios_cap' => $this->honorariosCapHours,
            'assigned_hours' => $this->assignedHours,
            'external_hours' => $this->externalHours,
            'over_band' => $overBand,
            'accepted' => $overBand && $this->remainingHours >= 0,
        ];
    }
}
