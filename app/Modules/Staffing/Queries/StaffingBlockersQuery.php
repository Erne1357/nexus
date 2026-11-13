<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\Violation;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lo que hoy impediría pasar la carga de un departamento a asignación docente, para que la asignación deshabilite
 * «Pasar a asignación docente» con su motivo en lugar de dejar que el jefe choque con el veto (tarea 3.9). Hace la
 * misma cuenta que el veto de Scheduling (BlockStaffingWithHardConflicts, docs/proceso/proceso-propuesto.md): los grupos
 * del departamento con choques duros, con el validador sobre la foto de ahora (olvida la que tuviera, es scoped), y,
 * en una modalidad con bloques de tiempo, los grupos no cancelados sin sesiones (de todos los campus de la carga, M1). El
 * veto sigue siendo quien decide al
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
        /** @var Department $department */
        $department = KeyLookup::findOrFail(Department::query()->withTrashed(), $load->department_id);

        $this->validator->forget();
        $hard = [];

        foreach (Campus::query()->whereIn('id', $this->groups($load)->select('campus_id'))->orderBy('code')->get() as $campus) {
            array_push($hard, ...array_filter(
                $this->validator->validateLoad($load->term, $campus, $department)->violations(),
                fn (Violation $violation): bool => $violation->severity === ConflictSeverity::Hard,
            ));
        }

        return [
            // Solo los grupos de esta carga: el validador de un campus también ve grupos de otra modalidad en él.
            'conflicted' => count(array_intersect(
                array_unique(array_filter(array_map(fn (Violation $violation): ?string => $violation->groupId, $hard))),
                $this->groups($load)->pluck('id')->all(),
            )),
            'unscheduled' => $load->modality->profile->usesTimeBlocks ? $this->unscheduled($load) : 0,
        ];
    }

    /**
     * Los grupos de la carga: los del departamento en su modalidad y periodo, de todos los campus (M1).
     *
     * @return Builder<Group>
     */
    private function groups(AcademicLoad $load): Builder
    {
        return Group::query()
            ->where('term_id', $load->term_id)
            ->where('modality_id', $load->modality_id)
            ->where('department_id', $load->department_id);
    }

    private function unscheduled(AcademicLoad $load): int
    {
        return $this->groups($load)
            ->where('status', '<>', GroupStatus::Cancelled->value)
            ->whereDoesntHave('sessions')
            ->count();
    }
}
