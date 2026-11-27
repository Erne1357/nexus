<?php

namespace App\Modules\Scheduling\Rules;

use App\Modules\Faculty\Rules\TeachingHours;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\State\StateTeacher;

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
 * PL-03 y HC-22 (confirmado el 27 de noviembre de 2026): quien tiene una plaza de apoyo y asistencia (`administrativeHours`, la
 * suma de sus horas) no lleva banda de ±2 ni respaldo del CSV, y puede no dar clases. Sus clases, horas externas (supuesto
 * por confirmar) y horas administrativas no pueden pasar de `staffing.administrative_max_hours` (42): es un límite duro
 * (HC-22, que veta la asignación y la elección en la selección), no un aviso, y HC-08 no le aplica (`over_band` siempre
 * falso). Su `limit` es lo que le queda hasta ese tope (`administrative_cap` menos las horas administrativas, nunca menos
 * de 0), `remaining_hours` es `limit` menos lo suyo (negativo si ya lo pasa) y `administrative_excess` las horas de más.
 *
 * `$exceptGroupId` no cuenta ese grupo: así el ranking pregunta cuántas horas le quedarían a un candidato sin contarse a
 * sí mismo.
 */
final class TeacherHourLimit
{
    /**
     * @return array{base_hours: int, teaching_hours: int, band_from: int, band_to: int, external_hours: int, assigned_hours: int, tolerance: int, honorarios_cap: ?int, band_limit: int, allowance_hours: ?int, limit: int, remaining_hours: int, over_band: bool, accepted: bool, hard: bool, groups: array<string, int>, administrative_hours: ?int, administrative_cap: ?int, administrative_excess: int, plaza_hours: int, extra_hours: int, with_honorarios_max: ?int, honorarios_excess: int}|null
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

        if ($teacher->administrativeHours !== null) {
            return self::administrative($teacher, $groups, $assigned, $total, $tolerance, (int) $state->setting('staffing.administrative_max_hours', TeachingHours::ADMINISTRATIVE_MAX));
        }

        $teaching = $teacher->teachingHours;
        $bandTo = $teaching + TeachingHours::MARGIN;
        $withHonorarios = max(1, (int) $state->setting('staffing.max_hours_with_honorarios', TeachingHours::MAX_WITH_HONORARIOS));
        // PL-04: horas de su plaza (las de Nexus o, sin plazas, las que corresponden a su renglón del CSV) y horas extra.
        $plaza = $teacher->baseHours > 0 ? $teacher->baseHours : ($teaching > 0 ? TeachingHours::plazaFromTeaching($teaching) : 0);
        $noPlaza = $plaza === 0 && $teaching === 0;
        $honorariosCap = $teacher->isHonorarios && $cap !== null ? (int) $cap : null;
        $bandLimit = $teacher->engineLimit ?? $honorariosCap ?? $bandTo + $tolerance;
        $limit = $teacher->engineLimit ?? max($bandLimit, $teacher->allowanceHours ?? $bandLimit);
        $overBand = $total > $bandLimit;
        $extra = $teacher->engineLimit !== null ? 0 : ($noPlaza ? $total : max(0, $total - ($bandTo + $tolerance)));
        $honorariosExcess = $teacher->engineLimit !== null || $noPlaza ? 0 : max(0, $plaza + $extra - $withHonorarios);

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
            'administrative_hours' => null,
            'administrative_cap' => null,
            'administrative_excess' => 0,
            'plaza_hours' => $plaza,
            'extra_hours' => $extra,
            'with_honorarios_max' => $noPlaza ? null : $withHonorarios,
            'honorarios_excess' => $honorariosExcess,
        ];
    }

    /**
     * PL-03 y HC-22: la cuenta de quien tiene plaza administrativa.
     *
     * @param  array<string, int>  $groups
     * @return array<string, mixed>
     */
    private static function administrative(StateTeacher $teacher, array $groups, int $assigned, int $total, int $tolerance, int $adminCap): array
    {
        $adminCap = max(1, $adminCap);
        $administrative = (int) $teacher->administrativeHours;
        $limit = max(0, $adminCap - $administrative);

        return [
            'base_hours' => $teacher->baseHours,
            'teaching_hours' => 0,
            'band_from' => 0,
            'band_to' => 0,
            'external_hours' => $teacher->externalHours,
            'assigned_hours' => $assigned,
            'tolerance' => $tolerance,
            'honorarios_cap' => null,
            'band_limit' => $limit,
            'allowance_hours' => $teacher->allowanceHours,
            'limit' => $limit,
            'remaining_hours' => $limit - $total,
            'over_band' => false,
            'accepted' => false,
            'hard' => false,
            'groups' => $groups,
            'administrative_hours' => $administrative,
            'administrative_cap' => $adminCap,
            'administrative_excess' => max(0, $total - $limit),
            'plaza_hours' => 0,
            'extra_hours' => 0,
            'with_honorarios_max' => null,
            'honorarios_excess' => 0,
        ];
    }

    /**
     * PL-03, el resumen de quien tiene plaza administrativa para mostrarlo: «Plaza administrativa: 36 h · clases 4 h ·
     * 40 de 42 h»; en el tope, «42 de 42 h · ya no le caben clases». Las clases incluyen las horas externas.
     *
     * @param  array{administrative_hours: ?int, administrative_cap: ?int, assigned_hours: int, external_hours: int}  $hours
     */
    public static function administrativeSummary(array $hours): string
    {
        $administrative = (int) $hours['administrative_hours'];
        $classes = $hours['assigned_hours'] + $hours['external_hours'];
        $cap = (int) $hours['administrative_cap'];
        $total = $administrative + $classes;
        $text = "Plaza administrativa: {$administrative} h · clases {$classes} h · {$total} de {$cap} h";

        return $total >= $cap ? "{$text} · ya no le caben clases" : $text;
    }

    /**
     * HC-22, el aviso de quien pasa el tope de su plaza administrativa, sin su nombre, o null si no lo pasa.
     *
     * @param  array{administrative_hours: ?int, administrative_cap: ?int, administrative_excess: int, assigned_hours: int, external_hours: int}  $hours
     */
    public static function administrativeNotice(array $hours): ?string
    {
        if ($hours['administrative_hours'] === null || $hours['administrative_excess'] <= 0) {
            return null;
        }

        $total = $hours['administrative_hours'] + $hours['assigned_hours'] + $hours['external_hours'];

        return "Pasa el tope de su plaza administrativa ({$hours['administrative_cap']} h): suma {$total} h entre su plaza ({$hours['administrative_hours']} h), clases y horas externas, {$hours['administrative_excess']} h de más.";
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
     * PL-04 y HC-22, el aviso de quien pasa su tope con honorarios (plaza + horas extra), sin su nombre, o null si no lo pasa.
     *
     * @param  array{plaza_hours: int, extra_hours: int, with_honorarios_max: ?int, honorarios_excess: int}  $hours
     */
    public static function honorariosNotice(array $hours): ?string
    {
        if ($hours['honorarios_excess'] <= 0) {
            return null;
        }

        return "Pasa el tope con honorarios ({$hours['with_honorarios_max']} h): {$hours['plaza_hours']} h de su plaza más {$hours['extra_hours']} h extra, {$hours['honorarios_excess']} h de más.";
    }

    /**
     * PL-04, el estado de las horas extra para mostrarlo: «2 h extra · honorarios por autorizar (jefe de depto)», «2 h de
     * honorarios autorizadas», «2 h extra no autorizadas · requiere oficio del docente (pendiente)» y, de quien no puede
     * tener honorarios, «2 h extra · Jefatura: sin honorarios · requiere oficio del docente (pendiente)».
     */
    public static function extraLabel(int $extra, string $state): string
    {
        return match ($state) {
            'authorized' => "{$extra} h de honorarios autorizadas",
            'unauthorized' => "{$extra} h extra no autorizadas · requiere oficio del docente (pendiente)",
            'jefatura' => "{$extra} h extra · Jefatura: sin honorarios · requiere oficio del docente (pendiente)",
            default => "{$extra} h extra · honorarios por autorizar (jefe de depto)",
        };
    }

    /**
     * El aviso de HC-08 sin el nombre del docente, o null si no pasa su banda: «Pasa su banda de horas frente a grupo
     * (22 h, de 20 a 24): lleva 26 h. 2 h extra · honorarios por autorizar (jefe de depto).» Con la casilla vigente que
     * cubre sus horas (SEL-12), «…: lleva 26 h, aceptado por el docente (casilla).». De honorarios con tope, «Pasa su
     * tope de honorarios (20 h): …». Con `extra_state` (el que decidió el jefe, PL-04) dice ese estado en vez del de por autorizar.
     *
     * @param  array{teaching_hours: int, band_from: int, band_to: int, honorarios_cap: ?int, assigned_hours: int, external_hours: int, over_band: bool, accepted: bool, extra_hours?: int, extra_state?: string}  $hours
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

        if ($hours['accepted']) {
            return "{$band}: lleva {$total} h, aceptado por el docente (casilla).";
        }

        $extra = $hours['extra_hours'] ?? null;

        return $extra === null || $extra <= 0
            ? "{$band}: lleva {$total} h. Sus horas extra quedan como honorarios por autorizar (jefe de depto)."
            : "{$band}: lleva {$total} h. ".self::extraLabel($extra, $hours['extra_state'] ?? 'pending').'.';
    }
}
