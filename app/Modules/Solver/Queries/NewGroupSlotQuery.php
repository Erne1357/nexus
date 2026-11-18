<?php

namespace App\Modules\Solver\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Rules\Reanalysis\NewGroupPlacement;
use App\Modules\Scheduling\Rules\Reanalysis\NewGroupSpec;
use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Solver\Contract\CampusGrid;
use App\Modules\Solver\Contract\GroupInput;
use App\Modules\Solver\Contract\HardViolation;
use App\Modules\Solver\Contract\ProposedSession;
use App\Modules\Solver\Contract\RoomInput;
use App\Modules\Solver\Contract\SessionKind;
use App\Modules\Solver\Contract\SessionSlot;
use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Contract\TimeSlot;
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
 * - forDepartment() solo trae los grupos de otros departamentos que comparten cohorte con un movible de la carga; el grupo
 *   nuevo puede tener una cohorte que ningún grupo de la carga tiene. Así que se agregan, fijos, los de esa cohorte que
 *   falten (cohortMates(): los únicos de su materia en el plan, el semestre y el campus, como Rules\Cohorts), solo con
 *   sus horas (sin docente ni salón: su salón ya está en la ocupación de los salones).
 * - Los préstamos vivos de salón (HC-10, propuestos o aceptados) entran como ocupación de su salón (withLoansAsBusy()):
 *   forDepartment() solo trae los aceptados de sus grupos, y así lo que propone el motor es lo mismo que revisa
 *   OpenReanalysisGroup al aplicar (NewGroupPlacementQuery::conflicts()).
 * - Si la carga no tiene grupos en el campus, el motor no ve sus salones: se dice sin armar la entrada.
 * - Corre solo la construcción (`search: null`): con un solo grupo movible, la construcción ya elige entre todos los
 *   candidatos del mejor nivel el de menor puntaje blando, y la búsqueda local no tiene con quién intercambiar. Con un
 *   límite corto (TIME_LIMIT_SECONDS), no el de la corrida del departamento: la llamada va en la petición.
 * - El evaluador solo cuenta lo que toca a los movibles, así que el puntaje blando de la salida es lo que agrega el grupo
 *   nuevo. Las violaciones duras de lo que ya está (los grupos fijos) no se cuentan: no son del grupo nuevo.
 *
 * Solo lee. Sin estado entre llamadas (Octane).
 */
final class NewGroupSlotQuery
{
    /** Segundos para colocar el grupo nuevo (en la demo masiva, 0.1 s): es solo el tope, porque va en la petición. */
    public const int TIME_LIMIT_SECONDS = 5;

    public function __construct(private readonly BuildSolverInput $inputs) {}

    /**
     * @param  (Closure(): float)|null  $clock  segundos de un reloj monótono; las pruebas dan uno propio
     */
    public function place(AcademicLoad $load, NewGroupSpec $spec, ?Closure $clock = null): NewGroupPlacement
    {
        $clock ??= fn (): float => hrtime(true) / 1e9;
        $started = $clock();

        if (! Group::query()->active()->where('term_id', $load->term_id)->where('modality_id', $load->modality_id)
            ->where('department_id', $load->department_id)->where('campus_id', $spec->campusId)->exists()) {
            return new NewGroupPlacement([], reasons: ["La carga no tiene grupos en {$spec->campusCode}, así que el motor no ve sus salones: ábrelo en Horarios."]);
        }

        $build = $this->inputs->forDepartment(
            Term::query()->whereKey($load->term_id)->firstOrFail(),
            Modality::query()->withTrashed()->whereKey($load->modality_id)->firstOrFail(),
            Department::query()->withTrashed()->whereKey($load->department_id)->firstOrFail(),
            "reanalysis-new-group:{$load->getKey()}",
        );
        $virtual = $this->virtualGroup($spec, $load);

        if (! in_array($spec->campusCode, array_map(fn (CampusGrid $campus): string => $campus->code, $build->input->campuses), true)) {
            return new NewGroupPlacement([], reasons: ["La carga no tiene grupos en {$spec->campusCode}: ábrelo en Horarios."], seconds: round($clock() - $started, 2));
        }

        $input = $this->withLoansAsBusy($this->withOnlyMovable($build->input, $virtual, $this->cohortMates($build->input, $spec, $load)), $load);
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
            evidence: $placed === [] ? [] : ['Sin choques duros con los '.number_format(count($input->groups) - 1).' grupos que deja fijos', $this->scoreLine($output->softScore->total, $byRule)],
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
     * Los grupos de la cohorte del grupo nuevo que la entrada no trae, fijos y solo con sus horas: los vivos de su plan,
     * semestre, campus y modalidad que son los únicos de su materia ahí (la cohorte del alumno tipo, Rules\Cohorts), sin
     * la materia del grupo nuevo.
     *
     * @return list<GroupInput>
     */
    private function cohortMates(SolverInput $input, NewGroupSpec $spec, AcademicLoad $load): array
    {
        $included = array_flip(array_map(fn (GroupInput $group): string => $group->id, $input->groups));
        $groups = Group::query()
            ->active()
            ->where('term_id', $load->term_id)
            ->where('modality_id', $load->modality_id)
            ->where('campus_id', $spec->campusId)
            ->where('study_plan_id', $spec->studyPlanId)
            ->where('semester', $spec->semester)
            ->with(['subject' => fn ($query) => $query->withTrashed(), 'sessions' => fn ($query) => $query->orderBy('day')->orderBy('starts_at')])
            ->orderBy('id')
            ->get();
        $perSubject = $groups->countBy('subject_id');

        return $groups
            ->filter(fn (Group $group): bool => ! isset($included[$group->id]) && $group->subject_id !== $spec->subjectId && $perSubject[$group->subject_id] === 1)
            ->map(fn (Group $group): GroupInput => new GroupInput(
                $group->id, $spec->campusCode, $group->subject->code, $group->department_id, $group->owner_career_id, $group->study_plan_id,
                $group->semester, $group->subject->theory_hours + $group->subject->practice_hours, $group->subject->practice_hours,
                $group->subject->needs_lab, max(1, $group->capacity), null, [$spec->cohortKey()],
                $group->sessions->map(fn (GroupSession $session): SessionSlot => new SessionSlot(
                    $session->day, Minutes::clock($session->starts_at), Minutes::clock($session->ends_at), SessionKind::from($session->kind->value), null,
                ))->all(),
                [], false,
            ))
            ->values()
            ->all();
    }

    /**
     * La entrada con los préstamos vivos de salón (propuestos o aceptados, de cualquier grupo) como ocupación de su salón.
     */
    private function withLoansAsBusy(SolverInput $input, AcademicLoad $load): SolverInput
    {
        $loans = InterdepartmentalAgreement::query()
            ->where('kind', AgreementKind::RoomLoan->value)
            ->where('term_id', $load->term_id)
            ->whereIn('status', AgreementStatus::liveValues())
            ->whereIn('room_id', array_map(fn (RoomInput $room): string => $room->id, $input->rooms))
            ->toBase()
            ->get(['room_id', 'day', 'starts_at', 'ends_at'])
            ->groupBy('room_id');

        if ($loans->isEmpty()) {
            return $input;
        }

        $rooms = array_map(function (RoomInput $room) use ($loans): RoomInput {
            $busy = [...$room->busy, ...($loans[$room->id] ?? collect())->map(fn (object $loan): TimeSlot => new TimeSlot((int) $loan->day, Minutes::clock($loan->starts_at), Minutes::clock($loan->ends_at)))->all()];
            usort($busy, fn (TimeSlot $a, TimeSlot $b): int => [$a->day, $a->startsAt, $a->endsAt] <=> [$b->day, $b->startsAt, $b->endsAt]);

            return new RoomInput($room->id, $room->campusCode, $room->type, $room->capacity, $room->buildingId, $room->ownerDepartmentId, $room->blocks, $busy);
        }, $input->rooms);

        return new SolverInput(
            $input->version, $input->runId, $input->termCode, $input->modalityCode, $input->campuses, $input->timeLimitSeconds,
            $input->scope, $input->travelMinutes, $input->weeklyHoursTolerance, $input->groups, $input->teachers, $rooms,
            $input->agreements, $input->subjectRoomDefaults, $input->hardRules, $input->softWeights, $input->options,
        );
    }

    /**
     * La entrada con todos sus grupos fijos (los movibles pasan sus sesiones de hoy a `fixed_sessions`), los de la cohorte
     * que faltaban y el grupo nuevo al final, como único movible, con un límite de tiempo corto.
     *
     * @param  list<GroupInput>  $mates
     */
    private function withOnlyMovable(SolverInput $input, GroupInput $virtual, array $mates): SolverInput
    {
        $fixed = array_map(fn (GroupInput $group): GroupInput => ! $group->movable ? $group : new GroupInput(
            $group->id, $group->campusCode, $group->subjectCode, $group->departmentId, $group->ownerCareerId, $group->studyPlanId,
            $group->semester, $group->weeklyHours, $group->practiceHours, $group->needsLab, $group->capacity, $group->teacherId,
            $group->cohorts, $group->currentSessions, [], false,
        ), $input->groups);

        return new SolverInput(
            $input->version, $input->runId, $input->termCode, $input->modalityCode, $input->campuses, self::TIME_LIMIT_SECONDS,
            $input->scope, $input->travelMinutes, $input->weeklyHoursTolerance, [...$fixed, ...$mates, $virtual], $input->teachers, $input->rooms,
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
