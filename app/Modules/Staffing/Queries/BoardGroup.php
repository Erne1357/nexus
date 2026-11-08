<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Enums\RankingState;
use App\Modules\Staffing\Models\GroupTeacherAssignment;

/**
 * Un grupo en el tablero de asignación: el grupo con su materia, su carrera dueña y su docente; su horario en una
 * línea y sus horas por semana; su asignación vigente con quién la hizo; los tres primeros lugares de su ranking,
 * cuántos tiene y en qué va.
 */
final readonly class BoardGroup
{
    /**
     * @param  list<RankingRow>  $candidates
     */
    public function __construct(
        public Group $group,
        public string $schedule,
        public string $hours,
        public ?GroupTeacherAssignment $assignment,
        public ?string $teacherPositions,
        public array $candidates,
        public int $rankingSize,
        public RankingState $rankingState,
    ) {}

    public function hasTeacher(): bool
    {
        return $this->group->teacher_id !== null;
    }
}
