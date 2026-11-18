<?php

namespace App\Modules\Solver\Contract;

/** Métricas de la corrida. Los contadores son opcionales: cada motor reporta los que tiene. */
final readonly class RunMetrics
{
    public function __construct(
        public float $elapsedSeconds,
        public string $engine,
        public string $engineVersion,
        public ?int $variables = null,
        public ?int $constraints = null,
        public ?int $iterations = null,
        public ?int $intermediateSolutions = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $path = ''): self
    {
        return new self(
            ContractFields::float($data, 'elapsed_seconds', $path),
            ContractFields::string($data, 'engine', $path),
            ContractFields::string($data, 'engine_version', $path),
            ContractFields::int($data, 'variables', $path, 0, null, false),
            ContractFields::int($data, 'constraints', $path, 0, null, false),
            ContractFields::int($data, 'iterations', $path, 0, null, false),
            ContractFields::int($data, 'intermediate_solutions', $path, 0, null, false),
        );
    }

    /**
     * @return array<string, int|float|string>
     */
    public function toArray(): array
    {
        return array_filter([
            'elapsed_seconds' => $this->elapsedSeconds,
            'engine' => $this->engine,
            'engine_version' => $this->engineVersion,
            'variables' => $this->variables,
            'constraints' => $this->constraints,
            'iterations' => $this->iterations,
            'intermediate_solutions' => $this->intermediateSolutions,
        ], fn (mixed $v): bool => $v !== null);
    }
}
