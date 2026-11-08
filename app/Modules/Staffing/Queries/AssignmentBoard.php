<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;

/**
 * Todo lo que muestra la asignación docente de un departamento en un periodo y campus (AssignmentBoardQuery).
 */
final readonly class AssignmentBoard
{
    /**
     * @param  list<BoardGroup>  $groups  por semestre de la materia, materia, carrera dueña y clave
     */
    public function __construct(
        public Term $term,
        public Campus $campus,
        public Department $department,
        public ?AcademicLoad $load,
        public array $groups,
    ) {}

    public function total(): int
    {
        return count($this->groups);
    }

    public function assignedCount(): int
    {
        return count(array_filter($this->groups, fn (BoardGroup $row): bool => $row->hasTeacher()));
    }
}
