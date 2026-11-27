<?php

namespace App\Modules\Core\Support;

/**
 * Las cadenas de seriación de una retícula (DESIGN.md, "Retícula del alumno"), las mismas en la ficha del alumno,
 * en el editor del plan y en la propuesta de carrera: cada grupo de dos o más materias unidas por su seriación
 * (unión y búsqueda), en el orden de su primera materia en la retícula. Un requisito que no está en la retícula
 * no une nada. Solo calcula: no lee la base ni guarda estado.
 */
final class SeriationChains
{
    /**
     * Los miembros de cada cadena, en el orden de `$ids`.
     *
     * @param  list<string>  $ids  las materias de la retícula, en el orden en que se ven
     * @param  array<string, list<string>>  $seriation  materia → las materias que pide por seriación
     * @return list<list<string>>
     */
    public static function of(array $ids, array $seriation): array
    {
        $root = array_combine($ids, $ids);
        $find = function (string $id) use (&$root): string {
            while ($root[$id] !== $id) {
                $id = $root[$id] = $root[$root[$id]];
            }

            return $id;
        };

        foreach ($seriation as $subjectId => $prerequisites) {
            foreach ($prerequisites as $prerequisite) {
                if (isset($root[$subjectId], $root[$prerequisite])) {
                    $root[$find($subjectId)] = $find($prerequisite);
                }
            }
        }

        $groups = [];

        foreach ($ids as $id) {
            $groups[$find($id)][] = $id;
        }

        return array_values(array_filter($groups, fn (array $members): bool => count($members) > 1));
    }
}
