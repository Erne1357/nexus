<?php

namespace App\Modules\Solver\Contract;

/** Una violación dura residual en la solución devuelta. */
final readonly class HardViolation
{
    /**
     * @param  list<string>  $groupIds
     */
    public function __construct(
        public string $rule,
        public array $groupIds,
        public string $message,
        public ?string $teacherId = null,
        public ?string $roomId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $path = ''): self
    {
        $rule = ContractFields::string($data, 'rule', $path);
        if (! preg_match('/^HC-\d{2}$/', $rule)) {
            throw ContractException::at(ContractFields::path($path, 'rule'), 'debe tener la forma HC-NN');
        }

        return new self(
            $rule,
            ContractFields::strings($data, 'group_ids', $path),
            ContractFields::string($data, 'message', $path),
            ContractFields::string($data, 'teacher_id', $path, false),
            ContractFields::string($data, 'room_id', $path, false),
        );
    }

    /**
     * @return array{rule: string, group_ids: list<string>, teacher_id?: string, room_id?: string, message: string}
     */
    public function toArray(): array
    {
        return array_filter([
            'rule' => $this->rule,
            'group_ids' => $this->groupIds,
            'teacher_id' => $this->teacherId,
            'room_id' => $this->roomId,
            'message' => $this->message,
        ], fn (mixed $v): bool => $v !== null);
    }
}
