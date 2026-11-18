<?php

namespace App\Modules\Solver\Contract;

/** Qué se programa: la carga de un departamento o la exploración de una carrera. */
final readonly class Scope
{
    public function __construct(public ScopeKind $kind, public string $id) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $path = ''): self
    {
        return new self(
            ContractFields::enum($data, 'kind', $path, ScopeKind::class),
            ContractFields::string($data, 'id', $path),
        );
    }

    /**
     * @return array{kind: string, id: string}
     */
    public function toArray(): array
    {
        return ['kind' => $this->kind->value, 'id' => $this->id];
    }
}
