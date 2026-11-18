<?php

namespace App\Modules\Solver\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Scheduling\Rules\Reanalysis\NewGroupPlacement;
use App\Modules\Scheduling\Rules\Reanalysis\NewGroupSpec;
use App\Modules\Solver\Contract\GroupInput;
use App\Modules\Solver\Contract\HardViolation;
use App\Modules\Solver\Contract\ProposedSession;
use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Engines\PhpHeuristic\PhpHeuristicEngine;
use Closure;

/**
 * Dónde cabe un grupo nuevo de una sugerencia del re-análisis (tarea 8.3b, «Abrir un grupo»), con la heurística en PHP:
 * contesta NewGroupPlacementRequested (Scheduling no ve al motor).
 *
 * - La entrada es la de la carga del departamento (BuildSolverInput::forDepartment) con **todos** sus grupos fijos
 *   (`movable = false`, sus sesiones de hoy como `fixed_sessions`) más un grupo nuevo virtual, el único movible
 *   (virtualGroup()): la materia, el campus, la capacidad, la carrera dueña con su plan y su semestre, sin docente y con la
 *   cohorte del alumno tipo de su semestre (NewGroupSpec::cohortKey()). Su id es `new:<materia>:<campus>`, el que Riel C
 *   acordó para los grupos virtuales de `BuildSolverInput::forGroups()` (8.7): cuando exista, esta entrada pasa a armarse
 *   con ella y el grupo virtual se le da tal cual.
 * - Corre solo la construcción (`search: null`): con un solo grupo movible, la construcción ya elige entre todos los
 *   candidatos del mejor nivel el de menor puntaje blando, y la búsqueda local no tiene con quién intercambiar. Con un
 *   límite corto (TIME_LIMIT_SECONDS), no el de la corrida del departamento.
 * - El evaluador solo cuenta lo que toca a los movibles, así que el puntaje blando de la salida es lo que agrega el grupo
 *   nuevo. Las violaciones duras de lo que ya está (los grupos fijos) no se cuentan: no son del grupo nuevo.
 *
 * Solo lee. Sin estado entre llamadas (Octane).
 */
final class NewGroupSlotQuery
{
    /** Segundos para colocar el grupo nuevo: la construcción tarda mucho menos; es solo el tope. */
    public const int TIME_LIMIT_SECONDS = 20;

    public function __construct(private readonly BuildSolverInput $inputs) {}

    /**
     * @param  (Closure(): float)|null  $clock  segundos de un reloj monótono; las pruebas dan uno propio
     */
    public function place(AcademicLoad $load, NewGroupSpec $spec, ?Closure $clock = null): NewGroupPlacement
    {
        $clock ??= fn (): float => hrtime(true) / 1e9;
        $started = $clock();

        $build = $this->inputs->forDepartment(
            Term::query()->whereKey($load->term_id)->firstOrFail(),
            Modality::query()->withTrashed()->whereKey($load->modality_id)->firstOrFail(),
            Department::query()->withTrashed()->whereKey($load->department_id)->firstOrFail(),
            "reanalysis-new-group:{$load->getKey()}",
        );
        $virtual = $this->virtualGroup($spec, $load);
        $input = $this->withOnlyMovable($build->input, $virtual);
        $output = (new PhpHeuristicEngine(search: null))->solve($input);

        $sessions = array_values(array_filter($output->sessions, fn (ProposedSession $session): bool => $session->groupId === $virtual->id));
        $violations = array_values(array_filter($output->hardViolations, fn (HardViolation $violation): bool => in_array($virtual->id, $violation->groupIds, true)));
        $reasons = [
            ...array_merge(...array_map(fn ($unscheduled): array => $unscheduled->groupId === $virtual->id ? $unscheduled->reasons : [], $output->unscheduled)),
            ...array_map(fn (HardViolation $violation): string => "{$violation->rule}: {$violation->message}", $violations),
        ];
        $placed = $reasons === [] ? $sessions : [];
        $byRule = array_filter($output->softScore->byRule);
        ksort($byRule);

        return new NewGroupPlacement(
            sessions: array_map(fn (ProposedSession $session): array => [
                'day' => $session->day,
                'startsAt' => $session->startsAt,
                'endsAt' => $session->endsAt,
                'kind' => $session->kind->value,
                'roomId' => $session->roomId,
            ], $placed),
            score: $placed === [] ? 0 : $output->softScore->total,
            scoreByRule: $placed === [] ? [] : $byRule,
            reasons: $placed === [] && $reasons === [] ? ['El motor no le encontró lugar.'] : $reasons,
            evidence: $placed === [] ? [] : ['Sin choques duros con los '.(count($input->groups) - 1).' grupos que deja fijos', $this->scoreLine($output->softScore->total, $byRule)],
            seconds: round($clock() - $started, 2),
        );
    }

    /**
     * El grupo nuevo como lo recibe el motor: el único movible, sin sesiones, sin docente y sin salones candidatos (todos los
     * del campus que le sirven, como a cualquier grupo). Aparte, para que pase tal cual a `forGroups()` (8.7) cuando exista.
     */
    public function virtualGroup(NewGroupSpec $spec, AcademicLoad $load): GroupInput
    {
        $subject = Subject::query()->withTrashed()->whereKey($spec->subjectId)->firstOrFail();

        return new GroupInput(
            id: "new:{$spec->subjectId}:{$spec->campusCode}",
            campusCode: $spec->campusCode,
            subjectCode: $subject->code,
            departmentId: $load->department_id,
            ownerCareerId: $spec->careerId,
            studyPlanId: $spec->studyPlanId,
            semester: $spec->semester,
            weeklyHours: $subject->theory_hours + $subject->practice_hours,
            practiceHours: $subject->practice_hours,
            needsLab: $subject->needs_lab,
            capacity: $spec->capacity,
            teacherId: null,
            cohorts: [$spec->cohortKey()],
            fixedSessions: [],
            candidateRoomIds: [],
            movable: true,
        );
    }

    /**
     * La entrada con todos sus grupos fijos (los movibles pasan sus sesiones de hoy a `fixed_sessions`) y el grupo nuevo
     * al final, como único movible, con un límite de tiempo corto.
     */
    private function withOnlyMovable(SolverInput $input, GroupInput $virtual): SolverInput
    {
        $fixed = array_map(fn (GroupInput $group): GroupInput => ! $group->movable ? $group : new GroupInput(
            $group->id, $group->campusCode, $group->subjectCode, $group->departmentId, $group->ownerCareerId, $group->studyPlanId,
            $group->semester, $group->weeklyHours, $group->practiceHours, $group->needsLab, $group->capacity, $group->teacherId,
            $group->cohorts, $group->currentSessions, [], false,
        ), $input->groups);

        return new SolverInput(
            $input->version, $input->runId, $input->termCode, $input->modalityCode, $input->campuses, self::TIME_LIMIT_SECONDS,
            $input->scope, $input->travelMinutes, $input->weeklyHoursTolerance, [...$fixed, $virtual], $input->teachers, $input->rooms,
            $input->agreements, $input->subjectRoomDefaults, $input->hardRules, $input->softWeights, $input->options,
        );
    }

    /**
     * «Puntaje blando que agrega: 6 (SC-01 2, SC-07 4)», o solo el total si es cero.
     *
     * @param  array<string, int>  $byRule
     */
    private function scoreLine(int $total, array $byRule): string
    {
        $parts = array_map(fn (string $rule, int $value): string => "{$rule} {$value}", array_keys($byRule), $byRule);

        return "Puntaje blando que agrega: {$total}".($parts === [] ? '' : ' ('.implode(', ', $parts).')');
    }
}
