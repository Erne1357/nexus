<?php

namespace App\Modules\Scheduling\Queries;

/**
 * Qué le falta a un grupo activo para cerrar la carga (HC-15), según ConflictBoardQuery: un choque duro vigente, sus
 * sesiones (en un campus con bloques de tiempo), su docente o el salón de alguna sesión presencial (en un campus que
 * usa salones).
 */
final readonly class GroupBoardStatus
{
    public function __construct(
        public string $id,
        public string $code,
        public string $subjectId,
        public string $departmentId,
        public string $careerId,
        public string $studyPlanId,
        public int $semester,
        public bool $hard,
        public bool $unscheduled,
        public bool $withoutTeacher,
        public bool $withoutRoom,
    ) {}

    /**
     * Si le falta algo para cerrar.
     */
    public function isMissing(): bool
    {
        return $this->hard || $this->unscheduled || $this->withoutTeacher || $this->withoutRoom;
    }

    /**
     * Si le falta algo que no es un choque duro (horario, docente o salón).
     */
    public function isPending(): bool
    {
        return $this->unscheduled || $this->withoutTeacher || $this->withoutRoom;
    }
}
