<?php

namespace App\Modules\Solver\Contract;

/** Ajustes opcionales de una corrida. */
final readonly class SolverOptions
{
    public function __construct(
        public ?int $threads = null,
        public ?int $seed = null,
        public bool $suggestTeachers = false,
        public bool $returnIntermediate = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $path = ''): self
    {
        return new self(
            ContractFields::int($data, 'threads', $path, 1, null, false),
            ContractFields::int($data, 'seed', $path, 0, null, false),
            ContractFields::bool($data, 'suggest_teachers', $path, false),
            ContractFields::bool($data, 'return_intermediate', $path, false),
        );
    }

    /**
     * @return array{threads?: int, seed?: int, suggest_teachers: bool, return_intermediate: bool}
     */
    public function toArray(): array
    {
        return array_filter([
            'threads' => $this->threads,
            'seed' => $this->seed,
            'suggest_teachers' => $this->suggestTeachers,
            'return_intermediate' => $this->returnIntermediate,
        ], fn (mixed $v): bool => $v !== null);
    }
}
