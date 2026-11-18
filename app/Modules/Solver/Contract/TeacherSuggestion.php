<?php

namespace App\Modules\Solver\Contract;

/** Un docente sugerido para un grupo sin docente. Es sugerencia; la asignación sigue el ranking de la fase 3. */
final readonly class TeacherSuggestion
{
    public function __construct(public string $groupId, public string $teacherId, public string $reason) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $path = ''): self
    {
        return new self(
            ContractFields::string($data, 'group_id', $path),
            ContractFields::string($data, 'teacher_id', $path),
            ContractFields::string($data, 'reason', $path),
        );
    }

    /**
     * @return array{group_id: string, teacher_id: string, reason: string}
     */
    public function toArray(): array
    {
        return ['group_id' => $this->groupId, 'teacher_id' => $this->teacherId, 'reason' => $this->reason];
    }
}
