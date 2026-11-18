<?php

namespace App\Modules\Scheduling\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un departamento armó los paralelos de una materia en un campus (tarea 8.8, ArmParallelGroups): cambió cupos, creó o
 * canceló grupos sueltos. Lleva, por id de carrera, solo las carreras cuyos lugares cambiaron: sus lugares en cada grupo
 * antes (cupos y propuestas vivas) y después, como `{code, seats}`, y el id del grupo donde le quedan más lugares (null si se
 * quedó sin ninguno). Lo escucha el aviso a los
 * coordinadores y a los jefes de esas carreras. Sale después del commit; solo lleva escalares, para la cola.
 */
final class ParallelGroupsArranged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  array<string, array{before: list<array{code: string, seats: int}>, after: list<array{code: string, seats: int}>, group: ?string}>  $careers
     */
    public function __construct(
        public string $termId,
        public string $modalityId,
        public string $campusId,
        public string $subjectId,
        public string $departmentId,
        public array $careers,
    ) {}
}
