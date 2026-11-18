<?php

namespace App\Modules\Scheduling\Queries;

/**
 * Un grupo de la sugerencia de paralelos (tarea 8.8): cuántos esperados de cada carrera van en él (`carrera => esperados`),
 * sus cupos por carrera, su carrera dueña, su capacidad y el grupo que ya existe y se queda en él (null si nace nuevo).
 * `full` es un completo de una carrera (los demás son los restos: mixtos o de una sola carrera).
 *
 * Cupos (confirmado el 18 de noviembre de 2026): en un mixto, cada carrera recibe sus esperados en exclusiva y lo demás
 * queda libre. En un grupo de una sola carrera, su cupo es toda la capacidad, como nace un grupo al aceptar una parte
 * (supuesto por confirmar). La dueña es la del grupo que se queda; en uno nuevo, la de más lugares (supuesto por confirmar).
 */
final readonly class ParallelSlot
{
    /**
     * @param  array<string, int>  $expected
     * @param  array<string, int>  $quotas
     */
    public function __construct(
        public array $expected,
        public array $quotas,
        public string $ownerCareerId,
        public int $capacity,
        public bool $full,
        public ?ParallelExisting $keeps,
    ) {}

    public function isMixed(): bool
    {
        return count($this->expected) > 1;
    }

    /**
     * Los lugares que no son de ninguna carrera.
     */
    public function free(): int
    {
        return max(0, $this->capacity - array_sum($this->quotas));
    }

    /**
     * Cuántos lugares pasan los cupos de la capacidad (HC-07); cero si caben.
     */
    public function over(): int
    {
        return max(0, array_sum($this->quotas) - $this->capacity);
    }
}
