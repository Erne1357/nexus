<?php

namespace App\Modules\Scheduling\Rules;

use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\State\StateGroup;
use App\Modules\Scheduling\Validation\State\StateSession;

/**
 * Las cohortes de un grupo para el motor de horarios (docs/arquitectura/solver.md, «Cohortes»): conjuntos de grupos que
 * un mismo alumno toma juntos y que no se empalman. Salen de HC-03, el alumno tipo, con la misma decisión de
 * TypicalStudentSchedule de quién cuenta: un grupo activo que es el único grupo activo de su materia en su plan,
 * semestre reticular, campus y modalidad (donde la materia tiene otro grupo hay combinación que elegir, y el alumno
 * tipo no queda atado a uno). Su clave es `plan:<study_plan_id>:<semestre>:<campus_code>`. Además, cada grupo activo
 * con paquete (DM-09) lleva `package:<package_id>:<campus_code>:<número>` (un grupo puede llevar las dos). Puro: solo lee la foto.
 *
 * Aparte, combinations() da la combinación del alumno tipo de los semestres con materias de varios grupos
 * (`combination:<study_plan_id>:<semestre>:<campus_code>`), para el motor: sin ella, el núcleo sin combinación de HC-03
 * no viajaba en el contrato (un grupo único podía quedar a la hora de cada opción de otra materia).
 */
final class Cohorts
{
    /** Marcador del grupo sin campus (como `CareerProposal::WITHOUT_CAMPUS`, que Scheduling no puede importar). */
    public const string WITHOUT_CAMPUS = 'none';

    /** El prefijo de la clave de la combinación del alumno tipo. */
    public const string COMBINATION = 'combination';

    /**
     * Las claves de cohorte de cada grupo dado, por id de grupo: lista vacía si no lleva ninguna.
     *
     * @param  iterable<StateGroup>  $groups
     * @return array<string, list<string>>
     */
    public static function of(ScheduleState $state, iterable $groups): array
    {
        $cohorts = [];

        foreach ($groups as $group) {
            $keys = [];

            if (self::isSoleGroupOfSubject($state, $group)) {
                $keys[] = "plan:{$group->studyPlanId}:{$group->semester}:{$state->campusCode($group->campusId)}";
            }

            if ($group->isActive && $group->packageId !== null && $group->packageNumber !== null) {
                $keys[] = self::packageKey($state, $group);
            }

            $cohorts[$group->id] = $keys;
        }

        return $cohorts;
    }

    /**
     * `package:<package_id>:<campus_code>:<número>` (DM-09): la instancia n de un paquete; sus grupos toman los mismos alumnos de
     * nuevo ingreso. El número se cuenta por campus, así que la clave lleva el código del campus.
     */
    public static function packageKey(ScheduleState $state, StateGroup $group): string
    {
        $campus = $group->campusId === '' ? self::WITHOUT_CAMPUS : $state->campusCode($group->campusId);

        return "package:{$group->packageId}:{$campus}:{$group->packageNumber}";
    }

    /**
     * La combinación del alumno tipo (HC-03), por id de grupo: en un plan, semestre, campus y modalidad donde alguna
     * materia tiene varios grupos activos, la clave `combination:<study_plan_id>:<semestre>:<campus_code>` va en un grupo
     * de cada materia (los únicos y el elegido de cada una de las demás); lista vacía en los demás grupos. Con la regla
     * del contrato (los grupos de una clave no se empalman) el motor conserva una combinación sin empalmes, que es lo que
     * pide HC-03; es más estricta (fija qué grupo de cada materia), como toda cohorte. Un semestre en que cada materia
     * tiene un solo grupo no la lleva: ahí basta `plan:`.
     *
     * El elegido de cada materia es el de la combinación sin empalmes que el alumno tipo tiene hoy (la primera que da
     * TypicalStudentCombination, con las materias y sus grupos por clave); si hoy no tiene ninguna, el primer grupo de
     * cada materia por su clave (supuesto por confirmar). Un grupo sin sesiones cabe con todo.
     *
     * @param  iterable<StateGroup>  $groups
     * @return array<string, list<string>>
     */
    public static function combinations(ScheduleState $state, iterable $groups): array
    {
        $chosen = [];
        $keys = [];

        foreach ($groups as $group) {
            $semester = "{$group->modalityId}|{$group->campusId}|{$group->studyPlanId}|{$group->semester}";
            $chosen[$semester] ??= self::combinationOf($state, $group);
            $keys[$group->id] = $group->isActive && isset($chosen[$semester][$group->id])
                ? [self::COMBINATION.":{$group->studyPlanId}:{$group->semester}:{$state->campusCode($group->campusId)}"]
                : [];
        }

        return $keys;
    }

    /**
     * Si la clave es la de la combinación del alumno tipo.
     */
    public static function isCombination(string $key): bool
    {
        return str_starts_with($key, self::COMBINATION.':');
    }

    /**
     * Las claves que cuentan para SC-01, los huecos del alumno tipo: todas menos `combination:`, cuyos grupos únicos ya
     * cuentan en `plan:` (contarla duplicaría sus huecos y cambiaría lo que mide SC-01).
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public static function forStudentGaps(array $keys): array
    {
        return array_values(array_filter($keys, fn (string $key): bool => ! self::isCombination($key)));
    }

    /**
     * Los grupos de la combinación del semestre del grupo, por id; vacío si cada materia tiene un solo grupo.
     *
     * @return array<string, true>
     */
    private static function combinationOf(ScheduleState $state, StateGroup $group): array
    {
        $bySubject = [];

        foreach ($state->groupsOfSemester($group->modalityId, $group->campusId, $group->studyPlanId, $group->semester) as $member) {
            $bySubject[$member->subjectId][] = $member;
        }

        if (array_filter($bySubject, fn (array $members): bool => count($members) > 1) === []) {
            return [];
        }

        uksort($bySubject, fn (int|string $a, int|string $b): int => [$state->subject((string) $a)?->code ?? '', (string) $a] <=> [$state->subject((string) $b)?->code ?? '', (string) $b]);

        foreach ($bySubject as $subjectId => $members) {
            usort($members, fn (StateGroup $a, StateGroup $b): int => [$a->code, $a->id] <=> [$b->code, $b->id]);
            $bySubject[$subjectId] = $members;
        }

        $options = array_map(fn (array $members): array => array_map(fn (StateGroup $member): array => array_map(
            fn (StateSession $session): array => ['day' => $session->day, 'start' => $session->start, 'end' => $session->end],
            $state->sessionsOf($member->id),
        ), $members), $bySubject);
        $picked = TypicalStudentCombination::find($options) ?? array_fill_keys(array_keys($bySubject), 0);
        $ids = [];

        foreach ($picked as $subjectId => $index) {
            $ids[$bySubject[$subjectId][$index]->id] = true;
        }

        return $ids;
    }

    private static function isSoleGroupOfSubject(ScheduleState $state, StateGroup $group): bool
    {
        if (! $group->isActive) {
            return false;
        }

        $sameSubject = array_filter(
            $state->groupsOfSemester($group->modalityId, $group->campusId, $group->studyPlanId, $group->semester),
            fn (StateGroup $other): bool => $other->subjectId === $group->subjectId,
        );

        return count($sameSubject) === 1;
    }
}
