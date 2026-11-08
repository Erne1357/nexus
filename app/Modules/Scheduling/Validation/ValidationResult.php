<?php

namespace App\Modules\Scheduling\Validation;

use App\Modules\Scheduling\Enums\ConflictSeverity;

/**
 * Las violaciones de una validación, sin repetidas y en el orden en que las encontraron las reglas.
 */
final readonly class ValidationResult
{
    /** @var list<Violation> */
    private array $violations;

    /**
     * @param  iterable<Violation>  $violations
     */
    public function __construct(iterable $violations = [])
    {
        $unique = [];

        foreach ($violations as $violation) {
            $unique[$violation->key()] ??= $violation;
        }

        $this->violations = array_values($unique);
    }

    /**
     * @return list<Violation>
     */
    public function violations(): array
    {
        return $this->violations;
    }

    public function isEmpty(): bool
    {
        return $this->violations === [];
    }

    public function hasHard(): bool
    {
        return array_any($this->violations, fn (Violation $violation): bool => $violation->severity === ConflictSeverity::Hard);
    }

    /**
     * Las de una regla (`HC-01`).
     *
     * @return list<Violation>
     */
    public function forRule(string $rule): array
    {
        return array_values(array_filter($this->violations, fn (Violation $violation): bool => $violation->rule === $rule));
    }

    /**
     * Las de un grupo.
     *
     * @return list<Violation>
     */
    public function forGroup(string $groupId): array
    {
        return array_values(array_filter($this->violations, fn (Violation $violation): bool => $violation->groupId === $groupId));
    }

    /**
     * Los mensajes, para mostrarlos juntos.
     *
     * @return list<string>
     */
    public function messages(): array
    {
        return array_map(fn (Violation $violation): string => $violation->message, $this->violations);
    }
}
