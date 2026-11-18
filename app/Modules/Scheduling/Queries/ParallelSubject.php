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
 *   en la bandeja), con algo suelto que pedir, por clave; `excluded`, las de parte regresada, que no cuentan;
 * - `parallels`: la cuenta de ParallelGroups con los esperados y grupos sueltos de cada carrera, con el tamaño objetivo;
 * - `groups`: los grupos sueltos que ya existen (de cualquier carrera), por orden de creación;
 * - `suggestion`: los grupos que sugiere Nexus (ParallelGroups::layout), cada uno con sus cupos y el grupo que se queda
 *   (ParallelPlacement), y `cancelled`, los que sobrarían, del de más avance al de menos;
 * - `whyNot`: por qué no hay nada que juntar, o null si juntando se ahorran grupos.
 */
final readonly class ParallelSubject
{
    /**
     * @param  list<ParallelCareer>  $careers
     * @param  list<ParallelCareer>  $excluded
     * @param  list<ParallelExisting>  $groups
     * @param  list<ParallelSlot>  $suggestion
     * @param  list<ParallelExisting>  $cancelled
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
    ) {}

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
     * Si sus grupos ya se juntaron: algún grupo suelto tiene cupos de dos carreras o más (supuesto por confirmar).
     */
    public function arranged(): bool
    {
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
     * Los grupos con docente que la sugerencia tendría que cancelar: un grupo con docente nunca se cancela.
     *
     * @return list<ParallelExisting>
     */
    public function blocked(): array
    {
        return array_values(array_filter($this->cancelled, fn (ParallelExisting $group): bool => $group->hasTeacher()));
    }

    /**
     * Por qué la sugerencia no se puede armar así, o null: una parte pendiente o un grupo con docente sin lugar. La carga
     * no editable la dice Parallels::$blockedReason.
     */
    public function arrangeBlockedReason(): ?string
    {
        $pending = array_map(fn (ParallelCareer $career): string => $career->career->code, $this->pending());

        if ($pending !== []) {
            return 'Falta aceptar la parte de '.self::list($pending).': sus lugares nacen al aceptarla.';
        }

        $blocked = $this->blocked()[0] ?? null;

        if ($blocked === null) {
            return null;
        }

        $owner = collect([...$this->careers, ...$this->excluded])->first(fn (ParallelCareer $career): bool => $career->career->getKey() === $blocked->ownerCareerId)?->career->code;

        return "El grupo {$blocked->code}".($owner === null ? '' : " de {$owner}").' tiene docente y el arreglo no le deja lugar: un grupo con docente no se cancela.';
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
