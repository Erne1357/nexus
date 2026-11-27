<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Scheduling\Models\Group;

/**
 * Un grupo en «Carga actual»: el grupo con su materia, carrera y docente; sus sesiones como fichas, lo que le falta
 * (horario, salón) y sus choques duros vigentes (solo lectura).
 */
final readonly class CurrentLoadGroup
{
    /**
     * @param  list<array{label: string, room: ?string, virtual: bool}>  $chips
     * @param  list<array{rule: string, message: string}>  $conflicts
     */
    public function __construct(
        public Group $group,
        public array $chips,
        public bool $unscheduled,
        public bool $withoutRoom,
        public array $conflicts,
    ) {}

    public function hasTeacher(): bool
    {
        return $this->group->teacher_id !== null;
    }
}
