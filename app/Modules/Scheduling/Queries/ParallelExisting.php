<?php

namespace App\Modules\Scheduling\Queries;

use Closure;

/**
 * Un grupo suelto (sin paquete) que ya existe de una materia y campus, como lo muestra la pestaña «Paralelos» (tarea 8.8):
 * su clave, su carrera dueña, su capacidad, su docente (el nombre, o null), sus sesiones y minutos colocados, sus cupos
 * por carrera (`carrera => lugares`) y, si un módulo de la derecha no deja cancelarlo (una solicitud de cambio abierta,
 * GroupsCancelling), su motivo (`vetoed`).
 */
final readonly class ParallelExisting
{
    /**
     * @param  array<string, int>  $quotas
     */
    public function __construct(
        public string $id,
        public string $code,
        public string $ownerCareerId,
        public int $capacity,
        public ?string $teacher,
        public int $sessions,
        public int $minutes,
        public array $quotas,
        public ?string $vetoed = null,
    ) {}

    public function hasTeacher(): bool
    {
        return $this->teacher !== null;
    }

    /**
     * Si no se puede cancelar: tiene docente o una solicitud de cambio abierta.
     */
    public function isLocked(): bool
    {
        return $this->hasTeacher() || $this->vetoed !== null;
    }

    /**
     * Por qué no se cancela, con la clave de su carrera dueña para el mensaje; null si se puede cancelar.
     */
    public function lockReason(?string $ownerCode): ?string
    {
        return match (true) {
            $this->hasTeacher() => $ownerCode === null
                ? "El grupo {$this->code} tiene docente y el arreglo no le deja lugar: deja un grupo con lugares de su carrera o quítale primero el docente."
                : "El grupo {$this->code} de {$ownerCode} tiene docente y el arreglo no le deja lugar: deja un grupo con lugares de {$ownerCode} o quítale primero el docente.",
            $this->vetoed !== null => $this->vetoed,
            default => null,
        };
    }

    /**
     * Por qué no se pueden cancelar los grupos de un armado que no se pueden cancelar, o null si no hay ninguno: uno solo,
     * con lockReason(); varios con docente, en una frase que los nombra a todos (de cuatro en adelante, los dos primeros
     * «y N más»: G-05 del recorrido final del ensayo 8.11), y después el motivo de cada uno con una solicitud de cambio
     * abierta.
     *
     * @param  list<self>  $locked
     * @param  Closure(string): ?string  $codeOf  la clave de una carrera por su id
     */
    public static function lockReasonOf(array $locked, Closure $codeOf): ?string
    {
        if (count($locked) <= 1) {
            return isset($locked[0]) ? $locked[0]->lockReason($codeOf($locked[0]->ownerCareerId)) : null;
        }

        $withTeacher = array_values(array_filter($locked, fn (self $group): bool => $group->hasTeacher()));
        $vetoes = array_values(array_unique(array_filter(array_map(fn (self $group): ?string => $group->hasTeacher() ? null : $group->vetoed, $locked))));
        $teachers = match (count($withTeacher)) {
            0 => null,
            1 => $withTeacher[0]->lockReason($codeOf($withTeacher[0]->ownerCareerId)),
            default => self::teachersReason($withTeacher, $codeOf),
        };

        return implode(' ', array_filter([$teachers, ...$vetoes]));
    }

    /**
     * «Los grupos 2, 3 y 2 más de ISIC tienen docente…» o, de varias carreras, «Los grupos 2 de IIND y 3 de ILOG…».
     *
     * @param  list<self>  $groups  dos o más, todos con docente
     * @param  Closure(string): ?string  $codeOf
     */
    private static function teachersReason(array $groups, Closure $codeOf): string
    {
        $owners = array_map(fn (self $group): ?string => $codeOf($group->ownerCareerId), $groups);
        $careers = array_values(array_unique(array_filter($owners)));
        $oneCareer = count($careers) === 1 && ! in_array(null, $owners, true);
        $names = array_map(fn (self $group, ?string $owner): string => $oneCareer || $owner === null ? $group->code : "{$group->code} de {$owner}", $groups, $owners);
        $shown = count($names) > 3 ? [$names[0], $names[1], (count($names) - 2).' más'] : $names;
        $room = $careers === [] ? 'de sus carreras' : 'de '.self::list($careers);

        return 'Los grupos '.self::list($shown).($oneCareer ? " de {$careers[0]}" : '')
            ." tienen docente y el arreglo no les deja lugar: deja grupos con lugares {$room} o quítales primero el docente.";
    }

    /**
     * «A», «A y B», «A, B y C».
     *
     * @param  list<string>  $items
     */
    private static function list(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items)." y {$last}";
    }

    /**
     * Lo que pide ParallelPlacement de cada grupo.
     *
     * @return array{id: string, owner: string, locked: bool, minutes: int}
     */
    public function forPlacement(): array
    {
        return ['id' => $this->id, 'owner' => $this->ownerCareerId, 'locked' => $this->isLocked(), 'minutes' => $this->minutes];
    }
}
