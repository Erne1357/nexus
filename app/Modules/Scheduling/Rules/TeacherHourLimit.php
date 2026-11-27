<?php

namespace App\Modules\Scheduling\Rules;

use App\Modules\Faculty\Rules\TeachingHours;
use App\Modules\Scheduling\Validation\ScheduleState;

/**
 * La cuenta de horas de un docente en el periodo que usa HC-08, para todos los que la necesitan (la regla, el ranking,
 * `teacher_term_loads` de Staffing y la entrada del motor). Horas asignadas = las que le cuenta al docente la materia
 * de cada grupo activo suyo (teoría + práctica, o 0 si no cuenta en su carga, PL-02), en todos los campus (supuesto
 * por confirmar).
 *
 * Desde la tarea 1.50 se mide contra sus **horas frente a grupo** (PL-01, con el respaldo del CSV de la selección) y no
 * contra las de su plaza: su banda va de frente a grupo − 2 a frente a grupo + 2 (art. 147) y su tope de banda
 * (`band_limit`) es frente a grupo + 2 + `staffing.extra_hours_tolerance` (por defecto 0; supuesto por confirmar), o
 * `staffing.honorarios_max_hours` si es de honorarios y el parámetro existe. El límite (`limit`) es ese tope o las horas
 * autorizadas del docente en el periodo, si son más (SEL-12: la casilla «aceptado por el docente»).
 *
 * - `over_band`: lleva más horas que su tope de banda. Para las personas es un aviso (HC-08 dejó de ser tope duro,
 *   confirmado el 26 de noviembre de 2026): requiere el oficio de conformidad del docente, que todavía no se firma en
 *   Nexus (pendiente), salvo que la casilla ya lo cubra (`accepted`).
 * - `hard`: la foto del motor trae su propio límite (`engineLimit`, ContractState) y ahí HC-08 sigue siendo choque duro.
 *
 * `$exceptGroupId` no cuenta ese grupo: así el ranking pregunta cuántas horas le quedarían a un candidato sin contarse a
 * sí mismo.
 */
final class TeacherHourLimit
{
    /**
     * @return array{base_hours: int, teaching_hours: int, band_from: int, band_to: int, external_hours: int, assigned_hours: int, tolerance: int, honorarios_cap: ?int, band_limit: int, allowance_hours: ?int, limit: int, remaining_hours: int, over_band: bool, accepted: bool, hard: bool, groups: array<string, int>}|null
     */
    public static function of(ScheduleState $state, string $teacherId, ?string $exceptGroupId = null): ?array
    {
        $teacher = $state->teacher($teacherId);

        if ($teacher === null) {
            return null;
        }

        $tolerance = (int) $state->setting('staffing.extra_hours_tolerance', 0);
        $cap = $state->setting('staffing.honorarios_max_hours');
        $groups = [];

        foreach ($state->groups() as $group) {
            if ($group->isActive && $group->teacherId === $teacherId && $group->id !== $exceptGroupId) {
                $groups[$group->id] = $state->subject($group->subjectId)?->teacherHours() ?? 0;
            }
        }

        $assigned = array_sum($groups);
        $total = $assigned + $teacher->externalHours;
        $teaching = $teacher->teachingHours;
        $bandTo = $teaching + TeachingHours::MARGIN;
        $honorariosCap = $teacher->isHonorarios && $cap !== null ? (int) $cap : null;
        $bandLimit = $teacher->engineLimit ?? $honorariosCap ?? $bandTo + $tolerance;
        $limit = $teacher->engineLimit ?? max($bandLimit, $teacher->allowanceHours ?? $bandLimit);
        $overBand = $total > $bandLimit;

        return [
            'base_hours' => $teacher->baseHours,
            'teaching_hours' => $teaching,
            'band_from' => max(0, $teaching - TeachingHours::MARGIN),
            'band_to' => $bandTo,
            'external_hours' => $teacher->externalHours,
            'assigned_hours' => $assigned,
            'tolerance' => $tolerance,
            'honorarios_cap' => $honorariosCap,
            'band_limit' => $bandLimit,
            'allowance_hours' => $teacher->allowanceHours,
            'limit' => $limit,
            'remaining_hours' => $limit - $total,
            'over_band' => $overBand,
            'accepted' => $overBand && $teacher->engineLimit === null && $teacher->allowanceHours !== null && $total <= $teacher->allowanceHours,
            'hard' => $teacher->engineLimit !== null,
            'groups' => $groups,
        ];
    }

    /**
     * La banda para mostrarla: «22 h (20 a 24)»; de honorarios con tope, «20 h (tope de honorarios)».
     *
     * @param  array{teaching_hours: int, band_from: int, band_to: int, honorarios_cap: ?int}  $hours
     */
    public static function bandLabel(array $hours): string
    {
        return $hours['honorarios_cap'] !== null
            ? "{$hours['honorarios_cap']} h (tope de honorarios)"
            : "{$hours['teaching_hours']} h ({$hours['band_from']} a {$hours['band_to']})";
    }

    /**
     * El aviso de HC-08 sin el nombre del docente, o null si no pasa su banda: «Pasa su banda de horas frente a grupo
     * (22 h, de 20 a 24): lleva 26 h. Requiere oficio de conformidad del docente (pendiente).» Con la casilla vigente que
     * cubre sus horas (SEL-12), «…: lleva 26 h, aceptado por el docente (casilla).». De honorarios con tope, «Pasa su
     * tope de honorarios (20 h): …».
     *
     * @param  array{teaching_hours: int, band_from: int, band_to: int, honorarios_cap: ?int, assigned_hours: int, external_hours: int, over_band: bool, accepted: bool}  $hours
     */
    public static function notice(array $hours): ?string
    {
        if (! $hours['over_band']) {
            return null;
        }

        $band = $hours['honorarios_cap'] !== null
            ? "Pasa su tope de honorarios ({$hours['honorarios_cap']} h)"
            : "Pasa su banda de horas frente a grupo ({$hours['teaching_hours']} h, de {$hours['band_from']} a {$hours['band_to']})";
        $total = $hours['assigned_hours'] + $hours['external_hours'];

        return $hours['accepted']
            ? "{$band}: lleva {$total} h, aceptado por el docente (casilla)."
            : "{$band}: lleva {$total} h. Requiere oficio de conformidad del docente (pendiente).";
    }
}
