<?php

namespace App\Modules\Solver\Contract;

/** Un grupo a programar (o, con `movable = false`, solo ocupación). */
final readonly class GroupInput
{
    /**
     * @param  list<string>  $cohorts  Claves de cohorte (HC-03).
     * @param  list<SessionSlot>  $fixedSessions
     * @param  list<string>  $candidateRoomIds  Vacía: sin acotar el dominio.
     * @param  list<SessionSlot>  $currentSessions  Opcional: lo que un grupo movible ya tiene, para que el motor marque `changed`.
     */
    public function __construct(
        public string $id,
        public string $campusCode,
        public string $subjectCode,
        public string $departmentId,
        public string $ownerCareerId,
        public string $studyPlanId,
        public int $semester,
        public int $weeklyHours,
        public int $practiceHours,
        public bool $needsLab,
        public int $capacity,
        public ?string $teacherId,
        public array $cohorts,
        public array $fixedSessions,
        public array $candidateRoomIds,
        public bool $movable,
        public array $currentSessions = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $path = ''): self
    {
        return new self(
            ContractFields::string($data, 'id', $path),
            ContractFields::string($data, 'campus_code', $path),
            ContractFields::string($data, 'subject_code', $path),
            ContractFields::string($data, 'department_id', $path),
            ContractFields::string($data, 'owner_career_id', $path),
            ContractFields::string($data, 'study_plan_id', $path),
            ContractFields::int($data, 'semester', $path, 1),
            ContractFields::int($data, 'weekly_hours', $path, 1),
            ContractFields::int($data, 'practice_hours', $path, 0),
            ContractFields::bool($data, 'needs_lab', $path),
            ContractFields::int($data, 'capacity', $path, 1),
            ContractFields::string($data, 'teacher_id', $path, false),
            ContractFields::strings($data, 'cohorts', $path, false),
            ContractFields::objects($data, 'fixed_sessions', $path, SessionSlot::class, false),
            ContractFields::strings($data, 'candidate_room_ids', $path, false),
            ContractFields::bool($data, 'movable', $path, true),
            ContractFields::objects($data, 'current_sessions', $path, SessionSlot::class, false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'campus_code' => $this->campusCode,
            'subject_code' => $this->subjectCode,
            'department_id' => $this->departmentId,
            'owner_career_id' => $this->ownerCareerId,
            'study_plan_id' => $this->studyPlanId,
            'semester' => $this->semester,
            'weekly_hours' => $this->weeklyHours,
            'practice_hours' => $this->practiceHours,
            'needs_lab' => $this->needsLab,
            'capacity' => $this->capacity,
            'teacher_id' => $this->teacherId,
            'cohorts' => $this->cohorts,
            'fixed_sessions' => array_map(fn (SessionSlot $s): array => $s->toArray(), $this->fixedSessions),
            'candidate_room_ids' => $this->candidateRoomIds,
            'movable' => $this->movable,
            'current_sessions' => array_map(fn (SessionSlot $s): array => $s->toArray(), $this->currentSessions),
        ], fn (mixed $v): bool => $v !== null);
    }
}
