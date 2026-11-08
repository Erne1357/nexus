<?php

namespace App\Modules\Scheduling\Rules\Constraints;

use App\Modules\Scheduling\Rules\TypicalStudentCombination;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\State\StateGroup;
use App\Modules\Scheduling\Validation\State\StateSession;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;

/**
 * HC-03: dos grupos de materias del mismo semestre reticular del mismo plan y campus no deben empalmarse si son los
 * únicos grupos de esas materias para esa carrera: el alumno tipo debe tener al menos una combinación sin empalme.
 *
 * Revisa cada plan y semestre de los grupos activos del alcance (el plan y semestre propios de cada grupo; una carrera
 * con varias retículas activas se revisa en cada una). Si TypicalStudentCombination encuentra combinación, no hay
 * choque. Si no: reporta cada par de grupos únicos de su materia que se empalman, en los dos grupos; y si ningún par
 * lo explica, reporta en los grupos de las materias del núcleo mínimo sin combinación.
 */
final class TypicalStudentSchedule implements Constraint
{
    public function rule(): string
    {
        return 'HC-03';
    }

    public function check(ScheduleState $state, ValidationScope $scope): iterable
    {
        foreach ($this->semestersIn($state, $scope) as [$campusId, $studyPlanId, $semester]) {
            $bySubject = [];

            foreach ($state->groupsOfSemester($campusId, $studyPlanId, $semester) as $group) {
                $bySubject[$group->subjectId][] = $group;
            }

            if (TypicalStudentCombination::exists($this->options($state, $bySubject))) {
                continue;
            }

            $pairs = [...$this->singleGroupOverlaps($state, $bySubject, $studyPlanId, $semester)];

            if ($pairs !== []) {
                yield from $pairs;

                continue;
            }

            yield from $this->coreViolations($state, $bySubject, $studyPlanId, $semester);
        }
    }

    /**
     * Los [campus, plan, semestre] de los grupos activos del alcance, sin repetir.
     *
     * @return list<array{string, string, int}>
     */
    private function semestersIn(ScheduleState $state, ValidationScope $scope): array
    {
        $semesters = [];

        foreach ($scope->groupIds() as $groupId) {
            $group = $state->group($groupId);

            if ($group !== null && $group->isActive) {
                $semesters["{$group->campusId}|{$group->studyPlanId}|{$group->semester}"] = [$group->campusId, $group->studyPlanId, $group->semester];
            }
        }

        return array_values($semesters);
    }

    /**
     * @param  array<string, list<StateGroup>>  $bySubject
     * @return array<string, list<list<array{day: int, start: int, end: int}>>>
     */
    private function options(ScheduleState $state, array $bySubject): array
    {
        return array_map(fn (array $groups): array => array_map(
            fn (StateGroup $group): array => array_map(
                fn (StateSession $session): array => ['day' => $session->day, 'start' => $session->start, 'end' => $session->end],
                $state->sessionsOf($group->id),
            ),
            $groups,
        ), $bySubject);
    }

    /**
     * Los empalmes entre grupos que son la única opción de su materia, reportados en los dos grupos.
     *
     * @param  array<string, list<StateGroup>>  $bySubject
     * @return iterable<Violation>
     */
    private function singleGroupOverlaps(ScheduleState $state, array $bySubject, string $studyPlanId, int $semester): iterable
    {
        $singles = array_values(array_map(fn (array $groups): StateGroup => $groups[0], array_filter($bySubject, fn (array $groups): bool => count($groups) === 1)));
        $count = count($singles);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $overlap = $this->firstOverlap($state, $singles[$i], $singles[$j]);

                if ($overlap === null) {
                    continue;
                }

                foreach ([[$singles[$i], $singles[$j], $overlap[0], $overlap[1]], [$singles[$j], $singles[$i], $overlap[1], $overlap[0]]] as [$mine, $other, $mySession, $otherSession]) {
                    yield Violation::hard(
                        $this->rule(),
                        "Se empalma con {$state->labelOf($other->id)} el {$otherSession->describe()}, la única opción de su materia en el semestre {$semester}: el alumno tipo no puede llevar las dos.",
                        'group',
                        $mine->id,
                        $mine->id,
                        ['study_plan_id' => $studyPlanId, 'semester' => $semester, 'other_group_id' => $other->id, 'session_id' => $mySession->id, 'other_session_id' => $otherSession->id],
                    );
                }
            }
        }
    }

    /**
     * El primer par de sesiones que se empalman entre dos grupos, como [sesión de a, sesión de b].
     *
     * @return array{StateSession, StateSession}|null
     */
    private function firstOverlap(ScheduleState $state, StateGroup $a, StateGroup $b): ?array
    {
        foreach ($state->sessionsOf($a->id) as $mine) {
            foreach ($state->sessionsOf($b->id) as $other) {
                if ($mine->overlaps($other)) {
                    return [$mine, $other];
                }
            }
        }

        return null;
    }

    /**
     * Sin pares que lo expliquen: un choque en cada grupo de las materias del núcleo mínimo sin combinación.
     *
     * @param  array<string, list<StateGroup>>  $bySubject
     * @return iterable<Violation>
     */
    private function coreViolations(ScheduleState $state, array $bySubject, string $studyPlanId, int $semester): iterable
    {
        $core = TypicalStudentCombination::unsatisfiableCore($this->options($state, $bySubject));
        $names = array_map(fn (string $subjectId): string => $state->subject($subjectId)?->name ?? $subjectId, $core);
        $last = array_pop($names);
        $list = $names === [] ? $last : implode(', ', $names).' y '.$last;
        $groupIds = [];

        foreach ($core as $subjectId) {
            foreach ($bySubject[$subjectId] as $group) {
                $groupIds[] = $group->id;
            }
        }

        foreach ($core as $subjectId) {
            foreach ($bySubject[$subjectId] as $group) {
                yield Violation::hard(
                    $this->rule(),
                    "En el semestre {$semester} el alumno tipo no tiene una combinación sin empalmes entre {$list}.",
                    'group',
                    $group->id,
                    $group->id,
                    ['study_plan_id' => $studyPlanId, 'semester' => $semester, 'subjects' => $core, 'groups' => $groupIds],
                );
            }
        }
    }
}
