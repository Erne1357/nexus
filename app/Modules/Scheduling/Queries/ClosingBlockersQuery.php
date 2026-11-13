<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Campus;
use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\Violation;
use Illuminate\Support\Collection;

/**
 * Lo que le falta a la carga de un departamento en una modalidad (M1) para cerrarse o publicarse (HC-15; confirmado el 10
 * de noviembre de 2026). Es la cuenta del veto (BlockClosingWithPendingGroups) y de la pantalla de cierre, que deshabilita
 * el botón con estos mismos motivos; el veto sigue decidiendo.
 *
 * Cuenta las banderas de cada grupo activo (GroupClosingFlagsQuery) con la regla por grupo de GroupClosingFlags
 * (ClosingBlockers::tally), la misma del tablero, con el perfil de la modalidad de la carga. El choque duro lo calcula el
 * validador en ese momento, en cada campus donde la carga tiene grupos y con la foto de todo el campus (HC-01 cruza
 * departamentos y campus), no `schedule_conflicts`, que el job pudo no recalcular todavía.
 * Olvida la foto antes, porque el validador es `scoped` y en el mismo request pudo haber leído una vieja (por ejemplo,
 * justo después de asignar el último salón).
 */
final class ClosingBlockersQuery
{
    public function __construct(
        private readonly ScheduleValidator $validator,
        private readonly GroupClosingFlagsQuery $flags,
    ) {}

    /**
     * @param  Collection<string, GroupClosingFlags>|null  $flags  las de la carga, si quien llama ya las leyó
     */
    public function of(AcademicLoad $load, ?Collection $flags = null): ClosingBlockers
    {
        $flags ??= $this->flags->ofLoad($load);

        if ($flags->isEmpty()) {
            return new ClosingBlockers;
        }

        return ClosingBlockers::tally($flags, $load->modality->profile, $this->conflicted($load, $flags));
    }

    /**
     * Los ids de los grupos del departamento con al menos un choque duro: el validador, que todavía lee por campus, en
     * cada campus donde están esos grupos.
     *
     * @param  Collection<string, GroupClosingFlags>  $flags
     * @return list<string>
     */
    private function conflicted(AcademicLoad $load, Collection $flags): array
    {
        $department = Department::query()->withTrashed()->whereKey($load->department_id)->firstOrFail();

        $this->validator->forget();
        $hard = [];

        foreach (Campus::query()->whereIn('id', $flags->pluck('campusId')->unique()->values()->all())->orderBy('code')->get() as $campus) {
            array_push($hard, ...array_filter(
                $this->validator->validateLoad($load->term, $campus, $department)->violations(),
                fn (Violation $violation): bool => $violation->severity === ConflictSeverity::Hard,
            ));
        }

        return array_values(array_intersect(array_unique(array_filter(array_map(fn (Violation $violation): ?string => $violation->groupId, $hard))), $flags->keys()->all()));
    }
}
