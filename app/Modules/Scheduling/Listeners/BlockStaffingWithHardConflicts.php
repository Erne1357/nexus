<?php

namespace App\Modules\Scheduling\Listeners;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\LoadTransitioning;
use App\Modules\Core\Models\Campus;
use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\Violation;

/**
 * Guarda de Scheduling para que una carga pase a asignación de docentes (`proposed` → `staffing`, y de vuelta desde
 * `adjusting` tras una solicitud de cambio): los grupos del departamento en la modalidad de la carga (M1, de todos sus
 * campus) no tienen choques duros y, en una modalidad con bloques de tiempo, todos tienen sesiones
 * (docs/proceso/proceso-propuesto.md, «grupos y sesiones sin choques duros»). Valida la carga en ese momento con el
 * validador, no con `schedule_conflicts`, que el job pudo no haber recalculado todavía (por eso puede vetar mientras el
 * tablero, que lee la tabla, aún no lo muestra). El validador todavía lee por campus: valida la carga en cada campus
 * donde tiene grupos, con la foto del campus completo porque HC-01 cruza departamentos y campus; la validación de 400
 * grupos tarda menos de 5 s. Veta
 * con un motivo por cosa que falta (evento con veto, LoadTransitioning).
 */
final class BlockStaffingWithHardConflicts
{
    private const array FROM = [LoadStatus::Proposed, LoadStatus::Adjusting];

    public function __construct(private readonly ScheduleValidator $validator) {}

    public function handle(LoadTransitioning $event): void
    {
        if ($event->to !== LoadStatus::Staffing || ! in_array($event->from, self::FROM, true)) {
            return;
        }

        $load = $event->load;
        $department = Department::query()->withTrashed()->findOrFail($load->department_id);

        $this->validator->forget();
        $loadGroups = Group::query()->where('term_id', $load->term_id)->where('modality_id', $load->modality_id)->where('department_id', $load->department_id);
        $hard = [];

        foreach (Campus::query()->whereIn('id', (clone $loadGroups)->select('campus_id'))->orderBy('code')->get() as $campus) {
            array_push($hard, ...array_filter(
                $this->validator->validateLoad($load->term, $campus, $department)->violations(),
                fn (Violation $violation): bool => $violation->severity === ConflictSeverity::Hard,
            ));
        }

        // Solo los grupos de esta carga: el validador de un campus también ve grupos de otra modalidad en él.
        $groups = count(array_intersect(
            array_unique(array_filter(array_map(fn (Violation $violation): ?string => $violation->groupId, $hard))),
            (clone $loadGroups)->pluck('id')->all(),
        ));

        if ($groups > 0) {
            $event->block("{$groups} ".($groups === 1 ? 'grupo tiene' : 'grupos tienen').' choques duros: corrígelos en el editor de horarios antes de asignar docentes (HC-15).');
        }

        if (! $load->modality->profile->usesTimeBlocks) {
            return;
        }

        $unscheduled = $loadGroups
            ->where('status', '<>', GroupStatus::Cancelled->value)
            ->whereDoesntHave('sessions')
            ->count();

        if ($unscheduled > 0) {
            $event->block("{$unscheduled} ".($unscheduled === 1 ? 'grupo no tiene' : 'grupos no tienen').' horario: acomódalos en el editor antes de asignar docentes.');
        }
    }
}
