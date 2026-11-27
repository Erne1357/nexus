<?php

namespace App\Modules\Staffing\Queries;

/**
 * Un docente hallado al buscar «otro docente» para un grupo (HC-21): sus datos, sus departamentos (el principal
 * primero), si es de otro departamento que el del grupo, el lugar que ocupa en el ranking del grupo (null si no está),
 * sus horas asignadas y base en el periodo del grupo y, si lo tiene, con qué choca en el horario del grupo.
 */
final readonly class TeacherSearchResult
{
    /**
     * @param  list<string>  $departments
     */
    public function __construct(
        public string $teacherId,
        public string $name,
        public ?string $rfc,
        public array $departments,
        public bool $otherDepartment,
        public ?int $rankingPlace,
        public ?int $assignedHours,
        public ?int $baseHours,
        public ?string $clash,
    ) {}
}
