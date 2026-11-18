<?php

namespace App\Modules\Solver\Contract;

/** Un grupo que no se pudo colocar, con los motivos (identificadores HC y texto). */
final readonly class UnscheduledGroup
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(public string $groupId, public array $reasons) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $path = ''): self
    {
        return new self(
            ContractFields::string($data, 'group_id', $path),
            ContractFields::strings($data, 'reasons', $path),
        );
    }

    /**
     * @return array{group_id: string, reasons: list<string>}
     */
    public function toArray(): array
    {
        return ['group_id' => $this->groupId, 'reasons' => $this->reasons];
    }
}
