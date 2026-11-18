<?php

namespace App\Modules\Solver\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Enums\AvailabilityKind;
use App\Modules\Faculty\Models\TeacherAvailability;
use App\Modules\Scheduling\Queries\ScheduleStateQuery;
use App\Modules\Scheduling\Rules\Cohorts;
use App\Modules\Scheduling\Rules\Constraints\CampusTravel;
use App\Modules\Scheduling\Rules\TeacherHourLimit;
use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\State\StateGroup;
use App\Modules\Scheduling\Validation\State\StateSession;
use App\Modules\Solver\Contract\CampusGrid;
use App\Modules\Solver\Contract\GroupInput;
use App\Modules\Solver\Contract\RoomAgreement;
use App\Modules\Solver\Contract\RoomInput;
use App\Modules\Solver\Contract\Scope;
use App\Modules\Solver\Contract\ScopeKind;
use App\Modules\Solver\Contract\SessionKind;
use App\Modules\Solver\Contract\SessionSlot;
use App\Modules\Solver\Contract\SoftWeights;
use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Contract\SolverOptions;
use App\Modules\Solver\Contract\TeacherInput;
use App\Modules\Solver\Contract\TimeSlot;
use App\Modules\Solver\Enums\RunScope;
use App\Modules\Solver\Rules\SolverSettings;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Arma el contrato de entrada del motor desde la base (tarea 6.4; docs/arquitectura/solver.md, «Contrato JSON» y
 * «Descomposición del problema»), a partir de la foto de ScheduleStateQuery para la modalidad.
 *
 * - Una corrida es la carga de un departamento en la modalidad, en todos sus campus, o los grupos de una carrera para
 *   explorar. Esos grupos son los movibles, con sus sesiones actuales en `current_sessions`.
 * - Todo lo demás entra fijo: los otros grupos activos de la modalidad que comparten docente, salón (por sus sesiones) o
 *   cohorte con un movible, como grupos `movable = false` con sus sesiones como `fixed_sessions`; los grupos de otras
 *   modalidades o que no se incluyen, como ocupación (`teachers[].busy`, `rooms[].busy`).
 * - Las cohortes son las de Cohorts (HC-03 hoy; los paquetes después).
 * - Aviso de tronco común (decisión del usuario, 17 de noviembre de 2026): si un movible comparte cohorte con un grupo
 *   de otro departamento que todavía no tiene sesiones, se avisa y no se impide nada.
 *
 * - Para las alternativas de una solicitud de cambio (8.7) y para abrir un grupo (8.3b), forGroups() mueve solo los grupos
 *   que se piden y grupos virtuales que todavía no existen (`new:<materia>:<campus>:<carrera>`); todo lo demás entra
 *   fijo, igual que arriba.
 *
 * Los parámetros: traslado `staffing.min_campus_travel_minutes` (SC-09), tiempo máximo de SolverSettings, reglas duras
 * HC-01 a HC-13 y los pesos de `solver.soft_weights` (por omisión, los de la tabla SC de docs/dominio/reglas-de-negocio.md). Los bloques preferidos
 * del docente (`preferred`) los lee de Faculty porque la foto no los trae, y los salones por defecto por materia
 * (`subject_room_defaults`) quedan vacíos mientras la foto no los traiga.
 */
final class BuildSolverInput
{
    /** @var list<string> */
    public const array HARD_RULES = ['HC-01', 'HC-02', 'HC-03', 'HC-04', 'HC-05', 'HC-06', 'HC-07', 'HC-08', 'HC-09', 'HC-10', 'HC-11', 'HC-12', 'HC-13'];

    /** El prefijo del id de un grupo virtual (`new:<materia>:<campus>:<carrera>`; acordado con la 8.3b): nunca se escribe en la base. */
    public const string VIRTUAL_PREFIX = 'new:';

    public function __construct(private readonly ScheduleStateQuery $states) {}

    /**
     * La carga del departamento en la modalidad: los grupos activos de sus materias, en todos los campus.
     */
    public function forDepartment(Term $term, Modality $modality, Department $department, string $runId): SolverInputBuild
    {
        $state = $this->states->forModality($term, $modality->id);

        return $this->build($state, $term, $modality, RunScope::Department, $department->id, $runId,
            fn (StateGroup $group): bool => $group->departmentId === $department->id);
    }

    /**
     * Los grupos activos de la modalidad cuya carrera dueña es la carrera, para explorar.
     */
    public function forCareer(Term $term, Modality $modality, Career $career, string $runId): SolverInputBuild
    {
        $state = $this->states->forModality($term, $modality->id);

        return $this->build($state, $term, $modality, RunScope::Career, $career->id, $runId,
            fn (StateGroup $group): bool => $group->ownerCareerId === $career->id);
    }

    /**
     * Solo los grupos pedidos se mueven, con sus sesiones actuales (`current_sessions`), más los grupos virtuales de
     * `$extraGroups`, que no existen en la base (8.3b, abrir un grupo) y nunca se escriben; todo lo demás entra fijo como
     * en forDepartment(). Es la entrada de ProposePlacements (tareas 8.7 y 8.3b).
     *
     * - Los movibles son grupos activos del periodo y la modalidad, y todos (también los virtuales) de un mismo
     *   departamento: el alcance del contrato es `department` con ese departamento, así que el contrato v1 no cambia.
     * - Un grupo virtual lleva un id `new:<materia>:<campus>:<carrera>`, es movible, no trae sesiones y su campus existe.
     *   Su cohorte de HC-03 se calcula como en Cohorts: `plan:<plan>:<semestre>:<campus>` si ningún grupo activo (ni otro
     *   virtual) de su materia está en ese semestre del alumno tipo; además conserva las claves que ya traiga. Los grupos
     *   fijos que comparten una de sus claves o su docente entran, como los de un movible. La cohorte de los grupos que ya
     *   existen no cambia aunque el virtual les quite ser el único de su materia (más estricto; supuesto por confirmar).
     * - La corrida (`run_id`) es otra cada vez si no se da (no hay fila en `solver_runs`) y el tiempo máximo, el de una
     *   corrida de departamento si no se da. `$state` deja reutilizar una foto ya leída de la modalidad.
     *
     * @param  list<string>  $movableGroupIds
     * @param  list<GroupInput>  $extraGroups
     *
     * @throws InvalidArgumentException si un grupo no es un grupo activo de la modalidad, un virtual no es válido, no hay
     *                                  grupos o son de varios departamentos
     */
    public function forGroups(Term $term, Modality $modality, array $movableGroupIds, array $extraGroups = [], ?string $runId = null,
        ?int $timeLimitSeconds = null, ?ScheduleState $state = null): SolverInput
    {
        $state ??= $this->states->forModality($term, $modality->id);
        $wanted = array_fill_keys($movableGroupIds, true);
        $departments = [];

        foreach (array_keys($wanted) as $groupId) {
            $group = $state->group((string) $groupId);

            if ($group === null || ! $group->isActive || $group->modalityId !== $modality->id) {
                throw new InvalidArgumentException("El grupo {$groupId} no es un grupo activo de {$modality->code} en {$term->code}.");
            }

            $departments[$group->departmentId] = true;
        }

        foreach ($extraGroups as $extra) {
            $departments[$extra->departmentId] = true;
        }

        if (count($departments) !== 1) {
            throw new InvalidArgumentException($departments === [] ? 'Pide al menos un grupo que mover.' : 'Los grupos que se mueven deben ser de un mismo departamento.');
        }

        return $this->build($state, $term, $modality, RunScope::Department, (string) array_key_first($departments), $runId ?? 'groups:'.Str::uuid7(),
            fn (StateGroup $group): bool => isset($wanted[$group->id]), $this->virtualGroups($state, $modality, $extraGroups), $timeLimitSeconds)->input;
    }

    /**
     * Los grupos virtuales validados, con su cohorte de HC-03 (ver forGroups()).
     *
     * @param  list<GroupInput>  $extraGroups
     * @return array<string, array{input: GroupInput, campusId: string}>
     */
    private function virtualGroups(ScheduleState $state, Modality $modality, array $extraGroups): array
    {
        $campusIds = Campus::query()->whereIn('code', array_map(fn (GroupInput $extra): string => $extra->campusCode, $extraGroups))->pluck('id', 'code');
        $sameSemester = [];

        foreach ($extraGroups as $extra) {
            $sameSemester[self::semesterKey($extra->subjectCode, $extra->campusCode, $extra->studyPlanId, $extra->semester)][] = $extra->id;
        }

        $virtual = [];

        foreach ($extraGroups as $extra) {
            $campusId = $campusIds[$extra->campusCode] ?? null;

            if (! str_starts_with($extra->id, self::VIRTUAL_PREFIX) || ! $extra->movable || isset($virtual[$extra->id]) || $campusId === null) {
                throw new InvalidArgumentException("El grupo virtual {$extra->id} no es válido: su id empieza con «".self::VIRTUAL_PREFIX.'», es movible, no se repite y su campus existe.');
            }

            $others = array_filter($state->groupsOfSemester($modality->id, (string) $campusId, $extra->studyPlanId, $extra->semester),
                fn (StateGroup $group): bool => $group->isActive && ($state->subject($group->subjectId)?->code ?? '') === $extra->subjectCode);
            $sole = $others === [] && count($sameSemester[self::semesterKey($extra->subjectCode, $extra->campusCode, $extra->studyPlanId, $extra->semester)]) === 1;
            $cohorts = array_values(array_unique([...($sole ? ["plan:{$extra->studyPlanId}:{$extra->semester}:{$extra->campusCode}"] : []), ...$extra->cohorts]));

            $virtual[$extra->id] = [
                'input' => new GroupInput($extra->id, $extra->campusCode, $extra->subjectCode, $extra->departmentId, $extra->ownerCareerId, $extra->studyPlanId,
                    $extra->semester, $extra->weeklyHours, $extra->practiceHours, $extra->needsLab, $extra->capacity, $extra->teacherId, $cohorts, [],
                    $extra->candidateRoomIds, true),
                'campusId' => (string) $campusId,
            ];
        }

        return $virtual;
    }

    private static function semesterKey(string $subjectCode, string $campusCode, string $studyPlanId, int $semester): string
    {
        return "{$subjectCode}|{$campusCode}|{$studyPlanId}|{$semester}";
    }

    /**
     * @param  callable(StateGroup): bool  $isInRun
     * @param  array<string, array{input: GroupInput, campusId: string}>  $virtual
     */
    private function build(ScheduleState $state, Term $term, Modality $modality, RunScope $scope, string $scopeId, string $runId, callable $isInRun,
        array $virtual = [], ?int $timeLimitSeconds = null): SolverInputBuild
    {
        $profile = $state->profile($modality->id);
        $usesRooms = $profile->usesRooms;

        $active = array_filter($state->groups(), fn (StateGroup $group): bool => $group->isActive && $group->modalityId === $modality->id);
        $movable = array_filter($active, $isInRun);
        $cohorts = Cohorts::of($state, $active);
        $extras = array_values(array_map(fn (array $entry): GroupInput => $entry['input'], $virtual));

        $fixed = $this->fixedGroups($state, $active, $movable, $cohorts, $extras);
        $included = $this->ordered($state, [...$movable, ...$fixed]);
        $includedIds = array_fill_keys(array_map(fn (StateGroup $group): string => $group->id, $included), true);

        $agreements = $usesRooms ? $this->agreements($state, $movable) : [];
        $roomIds = $usesRooms ? $this->usedRoomIds($state, $included, $agreements) : [];
        $campusIds = $this->campusIds($state, $included, $roomIds, array_column($virtual, 'campusId'));
        $extraTeachers = array_values(array_filter(array_map(fn (GroupInput $extra): ?string => $extra->teacherId, $extras)));
        $missing = array_values(array_filter($extraTeachers, fn (string $teacherId): bool => $state->teacher($teacherId) === null));
        $state = $missing === [] ? $state : $state->withTeachers($this->states->teachers($term, $missing));

        $settings = SolverSettings::fromSettings();

        $input = new SolverInput(
            SolverInput::VERSION,
            $runId,
            $term->code,
            $modality->code,
            $this->campuses($state, $campusIds, $modality->id),
            $timeLimitSeconds ?? $settings->timeLimitFor($scope),
            new Scope(ScopeKind::from($scope->value), $scopeId),
            (int) $state->setting('staffing.min_campus_travel_minutes', CampusTravel::DEFAULT_MINUTES),
            max(0, $profile->weeklyHoursTolerance ?? (int) $state->setting('scheduling.weekly_hours_tolerance', 0)),
            [...array_map(fn (StateGroup $group): GroupInput => $this->groupInput($state, $group, isset($movable[$group->id]), $cohorts[$group->id] ?? [], $usesRooms), $included), ...$extras],
            $this->teachers($state, $term, $included, $includedIds, $movable, $extraTeachers),
            $usesRooms ? $this->rooms($state, $campusIds, $roomIds, $includedIds) : [],
            $agreements,
            [],
            self::HARD_RULES,
            new SoftWeights($settings->softWeightsByRule()),
            new SolverOptions,
        );

        return new SolverInputBuild($input, $this->commonCoreWarnings($state, $active, $movable, $cohorts));
    }

    /**
     * Los grupos activos de la modalidad, fuera de la corrida, que comparten docente, salón o cohorte con un movible (o
     * docente o cohorte con un grupo virtual).
     *
     * @param  array<string, StateGroup>  $active
     * @param  array<string, StateGroup>  $movable
     * @param  array<string, list<string>>  $cohorts
     * @param  list<GroupInput>  $extras
     * @return array<string, StateGroup>
     */
    private function fixedGroups(ScheduleState $state, array $active, array $movable, array $cohorts, array $extras = []): array
    {
        $teachers = [];
        $rooms = [];
        $keys = [];

        foreach ($extras as $extra) {
            if ($extra->teacherId !== null) {
                $teachers[$extra->teacherId] = true;
            }

            foreach ($extra->cohorts as $key) {
                $keys[$key] = true;
            }
        }

        foreach ($movable as $group) {
            if ($group->teacherId !== null) {
                $teachers[$group->teacherId] = true;
            }

            foreach ($state->sessionsOf($group->id) as $session) {
                if ($session->roomId !== null) {
                    $rooms[$session->roomId] = true;
                }
            }

            foreach ($cohorts[$group->id] ?? [] as $key) {
                $keys[$key] = true;
            }
        }

        return array_filter($active, fn (StateGroup $group): bool => ! isset($movable[$group->id])
            && (($group->teacherId !== null && isset($teachers[$group->teacherId]))
                || array_filter($cohorts[$group->id] ?? [], fn (string $key): bool => isset($keys[$key])) !== []
                || array_filter($state->sessionsOf($group->id), fn (StateSession $session): bool => $session->roomId !== null && isset($rooms[$session->roomId])) !== []));
    }

    /**
     * Cada grupo por campus, materia y clave, para que la entrada salga igual con los mismos datos.
     *
     * @param  array<string, StateGroup>  $groups
     * @return list<StateGroup>
     */
    private function ordered(ScheduleState $state, array $groups): array
    {
        $sorted = array_values($groups);

        usort($sorted, fn (StateGroup $a, StateGroup $b): int => [$state->campusCode($a->campusId), $state->subject($a->subjectId)?->code ?? '', $a->code, $a->id]
            <=> [$state->campusCode($b->campusId), $state->subject($b->subjectId)?->code ?? '', $b->code, $b->id]);

        return $sorted;
    }

    /**
     * Los préstamos aceptados de los grupos movibles, a favor del departamento de cada grupo (HC-10), sin repetir.
     *
     * @param  array<string, StateGroup>  $movable
     * @return list<RoomAgreement>
     */
    private function agreements(ScheduleState $state, array $movable): array
    {
        $agreements = [];

        foreach ($movable as $group) {
            foreach ($state->loansOf($group->id) as $loan) {
                if (! $loan->isAccepted) {
                    continue;
                }

                $agreement = new RoomAgreement($loan->roomId, $group->departmentId, $loan->day, Minutes::format($loan->start), Minutes::format($loan->end));
                $agreements[json_encode($agreement->toArray())] = $agreement;
            }
        }

        ksort($agreements);

        return array_values($agreements);
    }

    /**
     * Los salones que las sesiones de los grupos incluidos y los acuerdos usan.
     *
     * @param  list<StateGroup>  $included
     * @param  list<RoomAgreement>  $agreements
     * @return array<string, true>
     */
    private function usedRoomIds(ScheduleState $state, array $included, array $agreements): array
    {
        $ids = [];

        foreach ($included as $group) {
            foreach ($state->sessionsOf($group->id) as $session) {
                if ($session->roomId !== null) {
                    $ids[$session->roomId] = true;
                }
            }
        }

        foreach ($agreements as $agreement) {
            $ids[$agreement->roomId] = true;
        }

        return $ids;
    }

    /**
     * Los campus de los grupos incluidos, de los salones que usan y de los grupos virtuales.
     *
     * @param  list<StateGroup>  $included
     * @param  array<string, true>  $roomIds
     * @param  list<string>  $extraCampusIds
     * @return list<string>
     */
    private function campusIds(ScheduleState $state, array $included, array $roomIds, array $extraCampusIds = []): array
    {
        $ids = array_fill_keys($extraCampusIds, true);

        foreach ($included as $group) {
            $ids[$group->campusId] = true;
        }

        foreach (array_keys($roomIds) as $roomId) {
            $campusId = $state->room($roomId)?->campusId;

            if ($campusId !== null) {
                $ids[$campusId] = true;
            }
        }

        $ids = array_keys($ids);
        usort($ids, fn (string $a, string $b): int => $state->campusCode($a) <=> $state->campusCode($b));

        return $ids;
    }

    /**
     * La rejilla de la modalidad en cada campus: el campus es solo el lugar, la ventana sale del perfil de la modalidad
     * (HC-04).
     *
     * @param  list<string>  $campusIds
     * @return list<CampusGrid>
     */
    private function campuses(ScheduleState $state, array $campusIds, string $modalityId): array
    {
        $profile = $state->profile($modalityId);

        return array_map(fn (string $campusId): CampusGrid => new CampusGrid(
            $state->campusCode($campusId), $profile->blockMinutes, $profile->dayStart, $profile->dayEnd, $profile->days, $profile->usesRooms,
        ), $campusIds);
    }

    /**
     * @param  list<string>  $cohortKeys
     */
    private function groupInput(ScheduleState $state, StateGroup $group, bool $isMovable, array $cohortKeys, bool $usesRooms): GroupInput
    {
        $subject = $state->subject($group->subjectId);
        $sessions = array_map(fn (StateSession $session): SessionSlot => new SessionSlot(
            $session->day,
            Minutes::format($session->start),
            Minutes::format($session->end),
            SessionKind::from($session->kind->value),
            $usesRooms ? $session->roomId : null,
        ), $state->sessionsOf($group->id));

        return new GroupInput(
            $group->id,
            $state->campusCode($group->campusId),
            $subject?->code ?? '',
            $group->departmentId,
            $group->ownerCareerId,
            $group->studyPlanId,
            $group->semester,
            $subject?->weeklyHours() ?? 0,
            $subject?->practiceHours ?? 0,
            $subject?->needsLab ?? false,
            $group->capacity,
            $group->teacherId,
            $cohortKeys,
            $isMovable ? [] : $sessions,
            [],
            $isMovable,
            $isMovable ? $sessions : [],
        );
    }

    /**
     * Los docentes de los grupos incluidos. Horas restantes: lo que le queda según HC-08 sin contar las horas de los
     * grupos movibles, que la corrida reprograma (TeacherHourLimit). Ocupación: sus sesiones en grupos que no entran a la
     * entrada (otras modalidades, o de esta sin relación con los movibles), con el campus de cada una (SC-09).
     *
     * Los docentes de los grupos virtuales también entran: sus horas no están en la base, así que no se suman.
     *
     * @param  list<StateGroup>  $included
     * @param  array<string, true>  $includedIds
     * @param  array<string, StateGroup>  $movable
     * @param  list<string>  $extraTeacherIds
     * @return list<TeacherInput>
     */
    private function teachers(ScheduleState $state, Term $term, array $included, array $includedIds, array $movable, array $extraTeacherIds = []): array
    {
        $ids = array_values(array_unique([...array_filter(array_map(fn (StateGroup $group): ?string => $group->teacherId, $included)), ...$extraTeacherIds]));
        sort($ids);

        $preferred = [];

        TeacherAvailability::query()->where('term_id', $term->id)->whereIn('teacher_id', $ids)
            ->where('kind', AvailabilityKind::Preferred->value)
            ->orderBy('day')->orderBy('starts_at')
            ->get(['teacher_id', 'day', 'starts_at', 'ends_at'])
            ->each(function (TeacherAvailability $block) use (&$preferred): void {
                $preferred[$block->teacher_id][] = new TimeSlot((int) $block->day, Minutes::clock($block->starts_at), Minutes::clock($block->ends_at));
            });

        return array_map(function (string $teacherId) use ($state, $includedIds, $movable, $preferred): TeacherInput {
            $teacher = $state->teacher($teacherId);
            $hours = TeacherHourLimit::of($state, $teacherId);
            $movableHours = array_sum(array_map(
                fn (StateGroup $group): int => $group->teacherId === $teacherId ? ($state->subject($group->subjectId)?->weeklyHours() ?? 0) : 0,
                $movable,
            ));

            $busy = [];

            foreach ($state->teacherSessions($teacherId) as $session) {
                if (! isset($includedIds[$session->groupId])) {
                    $busy[] = new TimeSlot($session->day, Minutes::format($session->start), Minutes::format($session->end), $state->campusCode($state->groupOrFail($session->groupId)->campusId));
                }
            }

            return new TeacherInput(
                $teacherId,
                max(0, ($hours['remaining_hours'] ?? 0) + $movableHours),
                $teacher?->isHonorarios ?? false,
                array_map(fn (array $block): TimeSlot => new TimeSlot($block[0], Minutes::format($block[1]), Minutes::format($block[2])), $teacher->unavailable ?? []),
                $preferred[$teacherId] ?? [],
                $this->sortedSlots($busy),
            );
        }, $ids);
    }

    /**
     * Los salones activos de los campus de la corrida y los que usan los grupos incluidos, con sus bloqueos y las
     * sesiones de los grupos que no entran a la entrada.
     *
     * @param  list<string>  $campusIds
     * @param  array<string, true>  $usedRoomIds
     * @param  array<string, true>  $includedIds
     * @return list<RoomInput>
     */
    private function rooms(ScheduleState $state, array $campusIds, array $usedRoomIds, array $includedIds): array
    {
        $campuses = array_flip($campusIds);
        $rooms = array_filter($state->rooms(), fn ($room): bool => isset($usedRoomIds[$room->id]) || ($room->isActive && isset($campuses[$room->campusId])));
        $buildings = Room::query()->withTrashed()->whereIn('id', array_keys($rooms))->pluck('building_id', 'id');

        $inputs = array_map(function ($room) use ($state, $includedIds, $buildings): RoomInput {
            $busy = [];

            foreach ($state->roomSessions($room->id) as $session) {
                if (! isset($includedIds[$session->groupId])) {
                    $busy[] = new TimeSlot($session->day, Minutes::format($session->start), Minutes::format($session->end));
                }
            }

            return new RoomInput(
                $room->id,
                $state->campusCode($room->campusId),
                $room->type->value,
                max(1, $room->capacity),
                (string) $buildings[$room->id],
                $room->departmentId,
                $this->sortedSlots(array_map(fn ($block): TimeSlot => new TimeSlot($block->day, Minutes::format($block->start), Minutes::format($block->end)), $state->blocksOf($room->id))),
                $this->sortedSlots($busy),
            );
        }, array_values($rooms));

        usort($inputs, fn (RoomInput $a, RoomInput $b): int => [$a->campusCode, $a->id] <=> [$b->campusCode, $b->id]);

        return $inputs;
    }

    /**
     * @param  list<TimeSlot>  $slots
     * @return list<TimeSlot>
     */
    private function sortedSlots(array $slots): array
    {
        usort($slots, fn (TimeSlot $a, TimeSlot $b): int => [$a->day, $a->startsAt, $a->endsAt] <=> [$b->day, $b->startsAt, $b->endsAt]);

        return $slots;
    }

    /**
     * Aviso de tronco común: los departamentos de otra carga que comparten cohorte con un movible y todavía no tienen
     * sesiones en esos grupos, con sus materias. No se exige nada (decisión del usuario, 17 de noviembre de 2026).
     *
     * @param  array<string, StateGroup>  $active
     * @param  array<string, StateGroup>  $movable
     * @param  array<string, list<string>>  $cohorts
     * @return list<string>
     */
    private function commonCoreWarnings(ScheduleState $state, array $active, array $movable, array $cohorts): array
    {
        $byKey = [];

        foreach ($active as $group) {
            foreach ($cohorts[$group->id] ?? [] as $key) {
                $byKey[$key][] = $group;
            }
        }

        $pending = [];

        foreach ($movable as $group) {
            foreach ($cohorts[$group->id] ?? [] as $key) {
                foreach ($byKey[$key] as $other) {
                    if (! isset($movable[$other->id]) && $other->departmentId !== $group->departmentId && $state->sessionsOf($other->id) === []) {
                        $pending[$other->departmentId][$state->subject($other->subjectId)?->code ?? $other->code] = true;
                    }
                }
            }
        }

        $names = Department::query()->withTrashed()->whereIn('id', array_keys($pending))->pluck('name', 'id');
        $warnings = [];

        foreach ($pending as $departmentId => $subjects) {
            $codes = array_keys($subjects);
            sort($codes);
            $last = array_pop($codes);
            $list = $codes === [] ? $last : implode(', ', $codes).' y '.$last;
            $warnings[($names[$departmentId] ?? $departmentId).'|'] = ($names[$departmentId] ?? $departmentId)." todavía no tiene horario aceptado para {$list}; lo que se acepte puede cambiar.";
        }

        ksort($warnings);

        return array_values($warnings);
    }
}
