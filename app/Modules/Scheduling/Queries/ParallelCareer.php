<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\AcademicLoad\Enums\InboxStatus;
use App\Modules\AcademicLoad\Models\ProposalItem;

/**
 * Lo que una carrera pide de una materia en un campus, en la pestaña «Paralelos» (tarea 8.8): el estado de su parte del
 * departamento, la fila de su propuesta (esperados, grupos pedidos y de paquete, plan y semestre) y lo que usa el reparto:
 * los esperados y grupos sueltos. Con paquetes en la fila, los esperados del reparto son los rezagados: esperados menos el
 * nuevo ingreso del campus (`newStudents`), que solo se resta en una fila de primer semestre (como SuggestedRowGroups); los
 * grupos de paquete no se juntan (confirmado el 18 de noviembre de 2026).
 */
final readonly class ParallelCareer
{
    public function __construct(
        public Career $career,
        public InboxStatus $status,
        public ProposalItem $item,
        public int $newStudents,
        public int $expected,
        public int $requested,
    ) {}

    public function isAccepted(): bool
    {
        return $this->status === InboxStatus::Accepted;
    }
}
