<?php

namespace App\Modules\AcademicLoad\Rules;

use InvalidArgumentException;

/**
 * Paralelos de una materia en la bandeja del departamento (reglas-de-negocio.md, "Propuesta de carrera"): los
 * grupos que piden todas las carreras contra los que bastan juntando a sus alumnos (confirmado el 2 de noviembre
 * de 2026). Con tamaño 35, ISC con 60 esperados e IIND con 40 piden 2 y 2, y bastan 3: uno completo de cada una
 * y uno mixto con el resto (25 de ISC y 5 de IIND).
 *
 * Por carrera, los completos son `floor(esperados / tamaño)` y su resto, `esperados mod tamaño`; los restos de
 * todas se juntan en `ceil(suma de restos / tamaño)` grupos mixtos, y el reparto de los mixtos es cuántos alumnos
 * de cada carrera van a ellos, sin repartirlos grupo por grupo (supuesto por confirmar). Lo mixto se arma de
 * verdad en la fase 3 con cupos por carrera (HC-07); esto solo es la sugerencia. Puro: no lee la base.
 */
final readonly class ParallelGroups
{
    /**
     * @param  array<string, array{expected: int, requested: int, full: int, remainder: int}>  $careers  por carrera, en el orden recibido
     * @param  array<string, int>  $mixedShares  alumnos de cada carrera con resto que van a los mixtos, en el orden recibido
     */
    private function __construct(
        public int $size,
        public array $careers,
        public int $mixed,
        public array $mixedShares,
        public int $requested,
        public int $suggested,
    ) {}

    /**
     * @param  int  $size  tamaño objetivo de la materia (SuggestedGroups::sizeFor)
     * @param  array<string, array{expected: int, requested: int}>  $demand  por carrera, sus alumnos esperados y grupos pedidos
     *
     * @throws InvalidArgumentException si el tamaño no es mayor que cero
     */
    public static function suggest(int $size, array $demand): self
    {
        if ($size <= 0) {
            throw new InvalidArgumentException("El tamaño objetivo debe ser mayor que cero: {$size}.");
        }

        $careers = [];
        $mixedShares = [];

        foreach ($demand as $career => $row) {
            $remainder = $row['expected'] % $size;
            $careers[$career] = [
                'expected' => $row['expected'],
                'requested' => $row['requested'],
                'full' => intdiv($row['expected'], $size),
                'remainder' => $remainder,
            ];

            if ($remainder > 0) {
                $mixedShares[$career] = $remainder;
            }
        }

        $mixed = intdiv(array_sum($mixedShares) + $size - 1, $size);
        $full = array_sum(array_column($careers, 'full'));

        return new self($size, $careers, $mixed, $mixedShares, (int) array_sum(array_column($careers, 'requested')), $full + $mixed);
    }

    /**
     * Cuántos grupos se ahorran contra lo pedido; negativo si lo pedido no alcanza para los esperados.
     */
    public function saved(): int
    {
        return $this->requested - $this->suggested;
    }

    /**
     * Si los grupos con los restos juntan alumnos de más de una carrera: con una sola, son grupos de esa carrera.
     */
    public function isMixed(): bool
    {
        return count($this->mixedShares) > 1;
    }

    /**
     * Los grupos de la sugerencia, cada uno como `carrera => esperados` (tarea 8.8, armar paralelos): primero los completos
     * de cada carrera, en el orden recibido, y luego los mixtos. Los restos se acomodan del más grande al más chico, cada
     * uno entero en el mixto donde mejor cabe (el de menos lugares libres que lo recibe); solo si no cabe en ninguno se
     * parte, empezando por el mixto con más lugares libres (supuesto por confirmar, maqueta B del 17 de noviembre de 2026).
     * En cada grupo, la carrera con más esperados primero; en un empate, el orden recibido.
     *
     * @return list<array<string, int>>
     */
    public function layout(): array
    {
        $groups = [];

        foreach ($this->careers as $career => $row) {
            for ($i = 0; $i < $row['full']; $i++) {
                $groups[] = [$career => $this->size];
            }
        }

        return [...$groups, ...$this->mixedGroups()];
    }

    /**
     * @return list<array<string, int>>
     */
    private function mixedGroups(): array
    {
        $bins = array_fill(0, $this->mixed, []);
        $free = array_fill(0, $this->mixed, $this->size);
        $shares = $this->mixedShares;
        uksort($shares, fn (string $a, string $b): int => $shares[$b] <=> $shares[$a]);

        foreach ($shares as $career => $left) {
            $fit = null;

            foreach ($free as $bin => $seats) {
                if ($seats >= $left && ($fit === null || $seats < $free[$fit])) {
                    $fit = $bin;
                }
            }

            if ($fit !== null) {
                $bins[$fit][$career] = $left;
                $free[$fit] -= $left;

                continue;
            }

            $roomiest = array_keys($free);
            usort($roomiest, fn (int $a, int $b): int => $free[$b] <=> $free[$a]);

            foreach ($roomiest as $bin) {
                $take = min($left, $free[$bin]);

                if ($take > 0) {
                    $bins[$bin][$career] = $take;
                    $free[$bin] -= $take;
                    $left -= $take;
                }
            }
        }

        return array_map(function (array $bin): array {
            uksort($bin, fn (string $a, string $b): int => $bin[$b] <=> $bin[$a]);

            return $bin;
        }, $bins);
    }
}
