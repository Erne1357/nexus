<?php

namespace App\Modules\Imports\Enums;

/**
 * Qué filas de un lote se listan en el asistente: las que traen error (no se aplican) o las que
 * traen advertencia (se aplican con ella).
 */
enum RowIssue: string
{
    case Errors = 'errors';
    case Warnings = 'warnings';

    public function label(): string
    {
        return match ($this) {
            self::Errors => 'Con error',
            self::Warnings => 'Con advertencia',
        };
    }
}
