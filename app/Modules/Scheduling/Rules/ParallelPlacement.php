<?php

namespace App\Modules\Scheduling\Rules;

/**
 * Armar paralelos (tarea 8.8; reglas-de-negocio.md, «Paralelos»): qué grupo suelto que ya existe de una materia y campus
 * se queda en cada grupo del arreglo, y cuáles sobran. La usan la sugerencia (ParallelsQuery) y la escritura
 * (ArmParallelGroups), así que las dos dicen lo mismo. Pura: no lee la base.
 *
 * - Se queda el de más avance (confirmado el 17 de noviembre de 2026): primero los que no se pueden cancelar (`locked`: con
 *   docente o con una solicitud de cambio abierta), luego los de más minutos colocados y, en un empate, el primero recibido
 *   (quien llama los manda por orden de creación).
 * - Un grupo que se queda conserva su carrera dueña (su plan y su semestre son de ella), así que solo entra en un grupo
 *   del arreglo que lleva lugares de su carrera; de esos, primero donde su carrera tiene más lugares (un completo de su
 *   carrera antes que un mixto donde es minoría) y, en un empate, el primero del arreglo (supuesto por confirmar).
 * - Se acomodan uno por uno del de más avance al de menos, como el voraz de un emparejamiento: si un grupo no encuentra
 *   lugar libre, se mueve a otro de los que ya se quedaron cuando eso le abre uno. Así se cancelan los de menos avance
 *   posibles: nunca se cancela uno con docente o con horario si había forma de dejarlo.
 * - Los que no encuentran lugar sobran (se cancelan); los que sobran sin poder cancelarse quedan bloqueados: un grupo con
 *   docente nunca se cancela (confirmado el 17 de noviembre de 2026) y uno con una solicitud de cambio abierta tampoco,
 *   hasta resolverla (supuesto por confirmar).
 */
final readonly class ParallelPlacement
{
    /**
     * @param  array<int, string>  $kept  por índice del grupo del arreglo, el id del grupo que se queda, en orden de índice
     * @param  list<string>  $cancelled  los que sobran, del de más avance al de menos
     * @param  list<string>  $blocked  los que sobran sin poder cancelarse
     */
    private function __construct(
        public array $kept,
        public array $cancelled,
        public array $blocked,
    ) {}

    /**
     * @param  list<array<string, int>>  $slots  cada grupo del arreglo, `carrera => lugares`
     * @param  list<array{id: string, owner: string, locked: bool, minutes: int}>  $groups  los sueltos que ya existen, por orden de creación
     */
    public static function place(array $slots, array $groups): self
    {
        $order = array_keys($groups);
        usort($order, fn (int $a, int $b): int => [$groups[$b]['locked'], $groups[$b]['minutes']] <=> [$groups[$a]['locked'], $groups[$a]['minutes']]);

        $preferences = [];

        foreach ($groups as $index => $group) {
            $fits = array_keys(array_filter($slots, fn (array $slot): bool => ($slot[$group['owner']] ?? 0) > 0));
            usort($fits, fn (int $a, int $b): int => $slots[$b][$group['owner']] <=> $slots[$a][$group['owner']]);
            $preferences[$index] = $fits;
        }

        $match = [];

        foreach ($order as $index) {
            $seen = [];
            self::augment($index, $preferences, $match, $seen);
        }

        ksort($match);
        $placed = array_flip($match);
        $left = array_values(array_filter($order, fn (int $index): bool => ! isset($placed[$index])));

        return new self(
            array_map(fn (int $index): string => $groups[$index]['id'], $match),
            array_map(fn (int $index): string => $groups[$index]['id'], $left),
            array_values(array_map(fn (int $index): string => $groups[$index]['id'], array_filter($left, fn (int $index): bool => $groups[$index]['locked']))),
        );
    }

    /**
     * Le busca lugar al grupo: uno libre de los que prefiere y, si no hay, uno ocupado cuyo grupo se pueda mover a otro.
     *
     * @param  array<int, list<int>>  $preferences
     * @param  array<int, int>  $match  por índice del arreglo, el índice del grupo
     * @param  array<int, true>  $seen
     */
    private static function augment(int $index, array $preferences, array &$match, array &$seen): bool
    {
        foreach ($preferences[$index] as $slot) {
            if (! isset($match[$slot])) {
                $match[$slot] = $index;

                return true;
            }
        }

        foreach ($preferences[$index] as $slot) {
            if (isset($seen[$slot])) {
                continue;
            }

            $seen[$slot] = true;

            if (self::augment($match[$slot], $preferences, $match, $seen)) {
                $match[$slot] = $index;

                return true;
            }
        }

        return false;
    }
}
