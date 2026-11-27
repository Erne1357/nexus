<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Models\AcademicLoad;
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
 * validador en ese momento, una vez con la foto de la modalidad de la carga en el periodo (con los grupos de todos sus
 * campus; HC-01 cruza departamentos, campus y modalidades), no `schedule_conflicts`, que el job pudo no recalcular
 * todavía; solo cuentan los grupos de la carga.
 * Olvida la foto antes, porque el validador es `scoped` y en el mismo request pudo haber leído una vieja (por ejemplo,
 * justo después de asignar el último salón).
 *
 * De la misma validación salen los avisos de HC-08 de los grupos de la carga (los docentes que pasan su banda de horas
 * frente a grupo, uno por docente): no bloquean, se muestran en «Para cerrar» (tarea 1.50).
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

        [$conflicted, $hourNotices] = $this->validated($load, $flags);

        return ClosingBlockers::tally($flags, $load->modality->profile, $conflicted)->withHourNotices($hourNotices);
    }

    /**
     * Con el validador, una vez por periodo y modalidad y con el alcance del departamento, cruzado con los grupos de la
     * carga: los ids de los grupos con al menos un choque duro y los avisos de HC-08 de sus docentes (uno por docente).
     *
     * @param  Collection<string, GroupClosingFlags>  $flags
     * @return array{list<string>, list<array{teacher_id: string, message: string}>}
     */
    private function validated(AcademicLoad $load, Collection $flags): array
    {
        $department = Department::query()->withTrashed()->whereKey($load->department_id)->firstOrFail();

        $this->validator->forget();
        $violations = $this->validator->validateLoad($load->term, $load->modality, $department)->violations();
        $ofLoad = array_flip($flags->keys()->all());
        $hard = array_filter($violations, fn (Violation $violation): bool => $violation->severity === ConflictSeverity::Hard);
        $notices = [];

        foreach ($violations as $violation) {
            if ($violation->rule === 'HC-08' && $violation->severity === ConflictSeverity::Soft && isset($ofLoad[$violation->groupId])) {
                $notices[$violation->subjectId] ??= ['teacher_id' => $violation->subjectId, 'message' => $violation->message];
            }
        }

        usort($notices, fn (array $a, array $b): int => strcmp($a['message'], $b['message']));

        return [
            array_values(array_intersect(array_unique(array_filter(array_map(fn (Violation $violation): ?string => $violation->groupId, $hard))), $flags->keys()->all())),
            $notices,
        ];
    }
}
