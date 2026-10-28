<?php

namespace App\Modules\Imports\Templates;

/**
 * Lo que la validación encontró en una fila, por campo: los errores impiden aplicarla y las
 * advertencias no. Es inmutable: `withError()` y `withWarning()` devuelven una copia.
 */
final readonly class RowIssues
{
    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<string, list<string>>  $warnings
     */
    public function __construct(
        public array $errors = [],
        public array $warnings = [],
    ) {}

    public function withError(string $field, string $message): self
    {
        $errors = $this->errors;
        $errors[$field][] = $message;

        return new self($errors, $this->warnings);
    }

    public function withWarning(string $field, string $message): self
    {
        $warnings = $this->warnings;
        $warnings[$field][] = $message;

        return new self($this->errors, $warnings);
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }
}
