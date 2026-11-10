<?php

namespace App\Modules\Scheduling\Queries;

/**
 * Qué le falta a un grupo activo para cerrar la carga (HC-15), según ConflictBoardQuery: un choque duro vigente, sus
 * sesiones (en un campus con bloques de tiempo), su docente o el salón de alguna sesión presencial (en un campus que
 * usa salones).
 *
 * Dónde cuenta por carrera (`placements`): en la carrera dueña, con su retícula y semestre, y en cada carrera con cupo
 * del grupo, en cada retícula suya donde está la materia y en el semestre que ahí tiene (como el editor de horarios).
 */
final readonly class GroupBoardStatus
{
    /**
     * @param  list<array{career: string, plan: string, semester: int}>  $placements
     */
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
        public array $placements = [],
    ) {}

    /**
     * Si cuenta en la carrera: es la dueña o le da cupo.
     */
    public function countsFor(string $careerId): bool
    {
        return array_any($this->placements, fn (array $placement): bool => $placement['career'] === $careerId);
    }

    /**
     * Si cuenta en la celda de la carrera, retícula y semestre.
     */
    public function placedIn(string $careerId, string $planId, int $semester): bool
    {
        return in_array(['career' => $careerId, 'plan' => $planId, 'semester' => $semester], $this->placements, true);
    }

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
