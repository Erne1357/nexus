<?php

namespace App\Modules\Scheduling\Validation\State;

/**
 * Un grupo en el estado que valida el validador: lo que las reglas necesitan de `groups`.
 */
final readonly class StateGroup
{
    public function __construct(
        public string $id,
        public string $campusId,
        public string $subjectId,
        public string $departmentId,
        public string $ownerCareerId,
        public string $studyPlanId,
        public int $semester,
        public string $code,
        public ?string $teacherId,
        public int $capacity,
        public bool $isActive = true,
    ) {}

    public function withTeacher(?string $teacherId): self
    {
        return new self($this->id, $this->campusId, $this->subjectId, $this->departmentId, $this->ownerCareerId,
            $this->studyPlanId, $this->semester, $this->code, $teacherId, $this->capacity, $this->isActive);
    }
}
