<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\Violation;

/**
 * Lo que hoy impediría pasar la carga de un departamento a asignación docente, para que la asignación deshabilite
 * «Pasar a asignación docente» con su motivo en lugar de dejar que el jefe choque con el veto (tarea 3.9). Hace la
 * misma cuenta que el veto de Scheduling (BlockStaffingWithHardConflicts, docs/proceso/proceso-propuesto.md): los grupos
 * del departamento con choques duros, con el validador sobre la foto de ahora (olvida la que tuviera, es scoped), y,
 * en un campus con bloques de tiempo, los grupos no cancelados sin sesiones. El veto sigue siendo quien decide al
 * pasarla.
 */
final class StaffingBlockersQuery
{
    public function __construct(private readonly ScheduleValidator $validator) {}

    /**
     * @return array{conflicted: int, unscheduled: int} cuántos grupos tienen choques duros y cuántos no tienen horario
     */
    public function of(AcademicLoad $load): array
    {
        $department = Department::query()->withTrashed()->findOrFail($load->department_id);

        $this->validator->forget();
        $hard = array_filter(
            $this->validator->validateLoad($load->term, $load->campus, $department)->violations(),
            fn (Violation $violation): bool => $violation->severity === ConflictSeverity::Hard,
        );

        return [
            'conflicted' => count(array_unique(array_filter(array_map(fn (Violation $violation): ?string => $violation->groupId, $hard)))),
            'unscheduled' => $load->campus->profile->usesTimeBlocks ? $this->unscheduled($load) : 0,
        ];
    }

    private function unscheduled(AcademicLoad $load): int
    {
        return Group::query()
            ->where('term_id', $load->term_id)
            ->where('campus_id', $load->campus_id)
            ->where('department_id', $load->department_id)
            ->where('status', '<>', GroupStatus::Cancelled->value)
            ->whereDoesntHave('sessions')
            ->count();
    }
}
