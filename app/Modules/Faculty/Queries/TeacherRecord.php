<?php

namespace App\Modules\Faculty\Queries;

use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Models\TeacherPosition;
use Illuminate\Support\Collection;

/**
 * Lo que muestra la ficha de un docente, de solo lectura (TeacherRecordQuery). Las tareas siguientes (disponibilidad,
 * perfil, historial y evaluaciones) agregan aquí lo suyo.
 *
 * `positionStatus` da, por id de plaza, `vigente`, `por_empezar` o `vencida` hoy; `countsInTerm`, si la plaza cuenta
 * en las horas base del periodo (S1, supuesto por confirmar: vigente el primer día). `baseHours` es null sin periodo.
 */
final readonly class TeacherRecord
{
    /**
     * @param  Collection<int, TeacherDepartment>  $adscriptions  La principal primero, con su departamento y campus.
     * @param  Collection<int, TeacherPosition>  $positions  Las vigentes primero, luego las por empezar y al final las vencidas.
     * @param  array<string, string>  $positionStatus
     * @param  array<string, bool>  $countsInTerm
     */
    public function __construct(
        public Teacher $teacher,
        public Collection $adscriptions,
        public Collection $positions,
        public array $positionStatus,
        public array $countsInTerm,
        public ?int $baseHours,
        public ?Term $term,
        public ?User $user,
    ) {}
}
