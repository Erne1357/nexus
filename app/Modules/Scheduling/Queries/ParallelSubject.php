<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Rules\ParallelGroups;
use App\Modules\Core\Models\Campus;

/**
 * Un renglón de la pestaña «Paralelos» (tarea 8.8): una materia del departamento en un campus (los paralelos no juntan
 * campus, M8). Lleva:
 *
 * - `careers`: las carreras que cuentan (parte aceptada, o pendiente que el departamento puede decidir, como ParallelGroups
 *   en la bandeja) con esperados sueltos, por clave; `excluded`, las que no cuentan: parte regresada, o sin esperados
 *   (aunque pidan grupos, no tienen alumnos que juntar);
 * - `parallels`: la cuenta de ParallelGroups con los esperados y grupos sueltos de cada carrera, con el tamaño de un
 *   completo: el objetivo sin pasar la capacidad de un grupo nuevo (ParallelsQuery::row(); E-08 del ensayo 8.11);
 * - `groups`: los grupos sueltos que ya existen (de cualquier carrera), por orden de creación;
 * - `suggestion`: los grupos que sugiere Nexus (ParallelGroups::layout), cada uno con sus cupos y el grupo que se queda
 *   (ParallelPlacement), sin pasar la capacidad real de ese grupo; `short`, los esperados de cada carrera que no caben en
 *   ningún grupo de la sugerencia (`carrera => alumnos`), y `cancelled`, los que sobrarían, del de más avance al de menos;
 * - `whyNot`: por qué no hay nada que juntar, o null si juntando se ahorran grupos;
 * - `newCapacity`: la capacidad de un grupo que naciera al armar (GroupCapacity), para la vista previa del drawer
 *   (ParallelDraft);
 * - `lastArrangement`: la foto del último armado con ArmParallelGroups (`grupo => [carrera => lugares]`), o null si nunca
 *   se armó (ParallelsQuery::lastArrangements()).
 */
final readonly class ParallelSubject
{
    /**
     * @param  list<ParallelCareer>  $careers
     * @param  list<ParallelCareer>  $excluded
     * @param  list<ParallelExisting>  $groups
     * @param  list<ParallelSlot>  $suggestion
     * @param  list<ParallelExisting>  $cancelled
     * @param  array<string, int>  $short
     * @param  array<string, array<string, int>>|null  $lastArrangement
     */
    public function __construct(
        public Subject $subject,
        public Campus $campus,
        public array $careers,
        public array $excluded,
        public ParallelGroups $parallels,
        public array $groups,
        public array $suggestion,
        public array $cancelled,
        public ?string $whyNot,
        public array $short = [],
        public int $newCapacity = 0,
        public ?array $lastArrangement = null,
    ) {}

    /**
     * El tamaño de un completo: el objetivo de la materia sin pasar la capacidad con que nace un grupo.
     */
    public function size(): int
    {
        return $this->parallels->size;
    }

    /**
     * Los grupos sueltos pedidos por las carreras que cuentan.
     */
    public function requested(): int
    {
        return $this->parallels->requested;
    }

    /**
     * Los grupos que bastan juntando.
     */
    public function suggested(): int
    {
        return $this->parallels->suggested;
    }

    /**
     * Los grupos de menos; negativo si lo pedido no alcanza.
     */
    public function saved(): int
    {
        return $this->parallels->saved();
    }

    /**
     * Los esperados que usa el reparto, de todas las carreras que cuentan.
     */
    public function expected(): int
    {
        return array_sum(array_map(fn (ParallelCareer $career): int => $career->expected, $this->careers));
    }

    /**
     * Si está armada (supuesto por confirmar): si ya se armó con ArmParallelGroups, mientras sus grupos sueltos vivos y sus
     * cupos sean los de la foto del último armado, aunque sea solo de completos y restos de una carrera; si cambiaron (otra
     * parte aceptada, otros cupos, un grupo cancelado), vuelve a estar por armar. Si nunca se armó, si algún grupo suelto ya
     * tiene cupos de dos carreras o más.
     */
    public function arranged(): bool
    {
        if ($this->lastArrangement !== null) {
            $live = [];

            foreach ($this->groups as $group) {
                $live[$group->id] = $group->quotas;
            }

            return $live == $this->lastArrangement;
        }

        return array_any($this->groups, fn (ParallelExisting $group): bool => count($group->quotas) > 1);
    }

    /**
     * Los grupos sueltos de una carrera (de los que es dueña).
     *
     * @return list<ParallelExisting>
     */
    public function groupsOf(string $careerId): array
    {
        return array_values(array_filter($this->groups, fn (ParallelExisting $group): bool => $group->ownerCareerId === $careerId));
    }

    /**
     * Las carreras que cuentan con su parte todavía pendiente: se ven, pero no se arma hasta aceptarlas (supuesto por
     * confirmar).
     *
     * @return list<ParallelCareer>
     */
    public function pending(): array
    {
        return array_values(array_filter($this->careers, fn (ParallelCareer $career): bool => ! $career->isAccepted()));
    }

    /**
     * Los grupos que la sugerencia tendría que cancelar y no se pueden cancelar: con docente (nunca se cancela) o con una
     * solicitud de cambio abierta.
     *
     * @return list<ParallelExisting>
     */
    public function blocked(): array
    {
        return array_values(array_filter($this->cancelled, fn (ParallelExisting $group): bool => $group->isLocked()));
    }

    /**
     * Por qué la sugerencia no se puede armar así, o null: una parte pendiente, un grupo que no se puede cancelar sin
     * lugar, o un grupo cuyos cupos pasan su capacidad real (HC-07). La carga no editable la dice Parallels::$blockedReason.
     */
    public function arrangeBlockedReason(): ?string
    {
        $pending = array_map(fn (ParallelCareer $career): string => $career->career->code, $this->pending());

        if ($pending !== []) {
            return 'Falta aceptar la parte de '.self::list($pending).': sus lugares nacen al aceptarla.';
        }

        $blocked = $this->blocked()[0] ?? null;

        if ($blocked !== null) {
            return $blocked->lockReason(collect([...$this->careers, ...$this->excluded])->first(fn (ParallelCareer $career): bool => $career->career->getKey() === $blocked->ownerCareerId)?->career->code);
        }

        foreach ($this->suggestion as $slot) {
            if ($slot->over() > 0) {
                $name = $slot->keeps === null ? 'un grupo nuevo' : "el grupo {$slot->keeps->code}";

                return 'HC-07: los cupos de '.$name.' suman '.array_sum($slot->quotas)." y es de {$slot->capacity} lugares.";
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $items
     */
    private static function list(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items)." y {$last}";
    }
}
