<?php

namespace App\Modules\Scheduling\Rules;

use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * HC-07 (reglas-de-negocio.md, "Cupos por carrera"): la suma de los cupos de un grupo no pasa su capacidad. Los
 * acuerdos propuestos no cuentan hasta que se aceptan (supuesto por confirmar). Los lugares de cada cupo van de 1 a
 * 999, como los de un acuerdo.
 */
final class QuotaRules
{
    public const int MAX_SEATS = 999;

    /**
     * @throws ValidationException
     */
    public function ensureWithinCapacity(int $total, int $capacity): void
    {
        if ($total > $capacity) {
            throw $this->overCapacity($total, $capacity);
        }
    }

    /**
     * Al aceptar un acuerdo: lo repartido más los lugares del acuerdo caben en la capacidad.
     *
     * @throws ValidationException
     */
    public function ensureAgreementFits(int $current, int $seats, int $capacity): void
    {
        if ($current + $seats > $capacity) {
            throw ValidationException::withMessages([
                'max_seats' => 'HC-07: el grupo ya tiene '.$current.' de '.$capacity.' lugares repartidos; caben '.max(0, $capacity - $current).'.',
            ]);
        }
    }

    public function overCapacity(int $total, int $capacity): ValidationException
    {
        return ValidationException::withMessages(['quotas' => "HC-07: los cupos suman {$total} y el grupo es de {$capacity} lugares."]);
    }

    /**
     * Si la excepción es el rechazo de HC-07 del trigger de la base (otra escritura cambió los cupos entre la
     * lectura y la escritura), la ValidationException con el mismo mensaje que la Action; si no, null.
     */
    public function fromDatabase(QueryException $e): ?ValidationException
    {
        if (preg_match('/HC-07: los cupos del grupo suman (\d+) y su capacidad es (\d+)/', $e->getMessage(), $m) !== 1) {
            return null;
        }

        return $this->overCapacity((int) $m[1], (int) $m[2]);
    }
}
