<?php

namespace App\Modules\Imports\Sii;

use Illuminate\Support\Facades\Log;
use PDOException;
use Throwable;

/**
 * Lo que va al log cuando la migración del SII falla: de la excepción y de cada causa anterior, la clase, dónde se
 * lanzó (`archivo:línea`, relativo al proyecto) y, si es de la base, su SQLSTATE. Nunca el mensaje ni los valores de
 * la consulta (bindings), que pueden traer datos personales; con la clase y el lugar se reproduce con un ensayo.
 */
final class SiiFailureLog
{
    public static function write(string $what, Throwable $exception): void
    {
        $chain = [];

        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $chain[] = array_filter([
                'exception' => $current::class,
                'at' => str_replace(base_path().'/', '', $current->getFile()).':'.$current->getLine(),
                'sqlstate' => $current instanceof PDOException ? (string) ($current->errorInfo[0] ?? $current->getCode()) : null,
            ], fn (?string $value): bool => $value !== null && $value !== '');
        }

        Log::error("Migración del SII: {$what}.", ['chain' => $chain]);
    }
}
