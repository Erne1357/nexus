<?php

namespace App\Modules\Solver\Contract;

/** El salón por defecto de una materia en un campus, con su prioridad (SC-03). */
final readonly class SubjectRoomDefault
{
    public function __construct(
        public string $subjectCode,
        public string $campusCode,
        public string $roomId,
        public int $priority,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $path = ''): self
    {
        return new self(
            ContractFields::string($data, 'subject_code', $path),
            ContractFields::string($data, 'campus_code', $path),
            ContractFields::string($data, 'room_id', $path),
            ContractFields::int($data, 'priority', $path, 0),
        );
    }

    /**
     * @return array{subject_code: string, campus_code: string, room_id: string, priority: int}
     */
    public function toArray(): array
    {
        return [
            'subject_code' => $this->subjectCode,
            'campus_code' => $this->campusCode,
            'room_id' => $this->roomId,
            'priority' => $this->priority,
        ];
    }
}
