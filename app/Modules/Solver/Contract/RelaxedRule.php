<?php

namespace App\Modules\Solver\Contract;

/** Una regla que el motor relajó en una instancia infactible, con los grupos que lo necesitaron. */
final readonly class RelaxedRule
{
    /**
     * @param  list<string>  $groupIds
     */
    public function __construct(public string $rule, public array $groupIds) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $path = ''): self
    {
        return new self(
            ContractFields::string($data, 'rule', $path),
            ContractFields::strings($data, 'group_ids', $path),
        );
    }

    /**
     * @return array{rule: string, group_ids: list<string>}
     */
    public function toArray(): array
    {
        return ['rule' => $this->rule, 'group_ids' => $this->groupIds];
    }
}
