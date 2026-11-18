<?php

namespace App\Modules\Solver\Contract;

use InvalidArgumentException;

/**
 * Un JSON que no cumple el contrato del motor de horarios. `path` es la ruta del campo malo (`groups[3].weekly_hours`)
 * y el mensaje la repite al inicio, para que el error se lea solo.
 */
final class ContractException extends InvalidArgumentException
{
    public function __construct(public readonly string $path, string $message)
    {
        parent::__construct($path === '' ? $message : "{$path}: {$message}");
    }

    public static function at(string $path, string $message): self
    {
        return new self($path, $message);
    }
}
