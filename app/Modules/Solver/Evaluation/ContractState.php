<?php

namespace App\Modules\Solver\Evaluation;

use App\Modules\Core\Models\ModalityProfile;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Rules\Soft\SoftContext;
use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ScheduleStateBuilder;
use App\Modules\Scheduling\Validation\State\StateGroup;
use App\Modules\Scheduling\Validation\State\StateRoom;
use App\Modules\Scheduling\Validation\State\StateRoomBlock;
use App\Modules\Scheduling\Validation\State\StateRoomLoan;
use App\Modules\Scheduling\Validation\State\StateSession;
use App\Modules\Scheduling\Validation\State\StateSubject;
use App\Modules\Scheduling\Validation\State\StateTeacher;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Solver\Contract\ContractException;
use App\Modules\Solver\Contract\GroupInput;
use App\Modules\Solver\Contract\ProposedSession;
use App\Modules\Solver\Contract\SessionSlot;
use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Contract\TimeSlot;

/**
 * El adaptador del contrato del motor (tarea 6.5): arma, con la entrada (SolverInput) y unas sesiones propuestas para
 * los grupos movibles, la foto en memoria (ScheduleState) que leen las reglas de Scheduling, para evaluar una solución
 * candidata con las mismas reglas que el validador, sin base. Lo usan ContractEvaluator, la heurística (6.6 y 6.7) y la
 * pantalla de corridas.
 *
 * Cómo se traduce, fiel a cómo las reglas leen la foto:
 *
 * - Cada grupo de la entrada es un grupo activo, con las sesiones propuestas si es movible (sin propuesta, sin horario)
 *   o sus `fixed_sessions` si no. El alcance son los movibles.
 * - El contrato no trae ids de materia, modalidad ni campus: la materia es su clave (`subject_code`, con las horas y el
 *   laboratorio del grupo), el campus es su clave y la modalidad es una por campus (`<modalidad>:<campus>`), con el
 *   perfil de la rejilla de ese campus (HC-04; con bloques de tiempo, y la tolerancia de HC-12 en cero porque el motor
 *   programa las horas exactas). Las reglas solo comparan igualdad.
 * - HC-03 sigue las cohortes de la entrada: cada clave `plan:<plan>:<semestre>:<campus>` mete al grupo en ese semestre
 *   del alumno tipo, y un grupo sin clave de plan no cuenta en ninguno (la entrada no trae los otros grupos de su
 *   materia, así que parecería el único). La clave `package:<id>:<campus>:<n>` (o la anterior, `package:<id>:<n>`) da
 *   el paquete y su número al grupo, que es lo que revisa TypicalStudentSchedule.
 * - La ocupación de fuera de la corrida entra como grupos de contexto (ids con el prefijo CONTEXT), activos y sin horas:
 *   los `busy` de un docente, uno por docente y campus (sin `campus_code`, el campus de su primer grupo; supuesto por
 *   confirmar), y los `busy` de un salón, uno por salón. Ninguna violación de un grupo de contexto sale del adaptador.
 * - HC-08: las horas base del docente son sus `remaining_hours` más las horas de sus grupos fijos de la entrada, sin
 *   horas externas ni tolerancia, así que lo que le queda es lo mismo que en la base.
 * - HC-10: cada préstamo aceptado (`agreements`) es de todos los grupos del departamento al que se presta.
 * - HC-07 y HC-13 no se pueden revisar: el contrato no trae cupos ni catálogos (se verifican al armar la entrada; la foto
 *   da todos los planes activos y las carreras ofrecidas).
 * - SC-09 usa `travel_minutes`; SC-01 las cohortes de la entrada; SC-03, SC-04, SC-06 y SC-08 lo que trae la entrada
 *   (SoftContext).
 */
final readonly class ContractState
{
    /** Prefijo de los grupos de contexto: la ocupación de docentes y salones fuera de la corrida. */
    public const string CONTEXT = 'busy:';

    /** La materia de los grupos de contexto: sin horas, para que no cuente en HC-08. */
    private const string CONTEXT_SUBJECT = 'busy:subject';

    /**
     * @param  array<string, true>  $groupIds  los grupos de la entrada
     * @param  list<string>  $hardRules
     * @param  array<string, int>  $softWeights
     */
    private function __construct(
        public ScheduleState $state,
        public ValidationScope $scope,
        public SoftContext $context,
        public array $hardRules,
        public array $softWeights,
        private array $groupIds,
    ) {}

    /**
     * La foto de la entrada con las sesiones propuestas de sus grupos movibles.
     *
     * @param  list<ProposedSession>  $sessions
     *
     * @throws ContractException si una sesión es de un grupo que no está en la entrada o que no es movible
     */
    public static function from(SolverInput $input, array $sessions): self
    {
        $groups = [];

        foreach ($input->groups as $group) {
            $groups[$group->id] = $group;
        }

        $proposed = [];

        foreach ($sessions as $i => $session) {
            $group = $groups[$session->groupId] ?? null;

            if ($group === null || ! $group->movable) {
                throw ContractException::at("sessions[{$i}].group_id", $group === null ? 'el grupo no está en la entrada' : 'el grupo no es movible');
            }

            $proposed[$session->groupId][] = $session;
        }

        $builder = new ScheduleStateBuilder($input->termCode);
        $builder->subject(new StateSubject(self::CONTEXT_SUBJECT, '', 'Una clase fuera de esta corrida', 0, 0));
        $builder->setting('staffing.min_campus_travel_minutes', $input->travelMinutes);

        foreach ($input->campuses as $grid) {
            $builder->campusCode($grid->code, $grid->code);
            $builder->profile(self::modalityOf($input, $grid->code), ModalityProfile::fromArray([
                'uses_rooms' => $grid->usesRooms,
                'uses_time_blocks' => true,
                'block_minutes' => $grid->blockMinutes,
                'day_start' => $grid->dayStart,
                'day_end' => $grid->dayEnd,
                'days' => $grid->days,
            ]));
        }

        $cohorts = [];
        $fixedHours = [];
        $byDepartment = [];
        $roomDefaults = [];

        foreach ($input->groups as $group) {
            $subjectId = self::subjectOf($group);
            [$semesters, $package] = self::cohortsOf($input, $group);

            $builder->subject(new StateSubject($subjectId, $group->subjectCode, $group->subjectCode === '' ? 'La materia' : $group->subjectCode,
                max(0, $group->weeklyHours - $group->practiceHours), $group->practiceHours, $group->needsLab));
            $builder->group(new StateGroup($group->id, $group->campusCode, self::modalityOf($input, $group->campusCode), $subjectId,
                $group->departmentId, $group->ownerCareerId, $group->studyPlanId, $group->semester, $group->id, $group->teacherId,
                $group->capacity, true, $package[0] ?? null, $package[1] ?? null));
            $builder->typicalStudentSemesters($group->id, $semesters);
            $builder->offering($group->ownerCareerId, $group->campusCode);
            $builder->activePlan($group->studyPlanId);

            $slots = $group->movable ? array_map(fn (ProposedSession $session): array => [$session->day, $session->startsAt, $session->endsAt, $session->roomId, $session->kind->value], $proposed[$group->id] ?? [])
                : array_map(fn (SessionSlot $slot): array => [$slot->day, $slot->startsAt, $slot->endsAt, $slot->roomId, $slot->kind->value], $group->fixedSessions);

            foreach ($slots as $n => [$day, $startsAt, $endsAt, $roomId, $kind]) {
                $builder->session(new StateSession("{$group->id}#{$n}", $group->id, $day, Minutes::of($startsAt), Minutes::of($endsAt), $roomId, SessionKind::from($kind)));
            }

            if (! $group->movable && $group->teacherId !== null) {
                $fixedHours[$group->teacherId] = ($fixedHours[$group->teacherId] ?? 0) + $group->weeklyHours;
            }

            $cohorts[$group->id] = $group->cohorts;
            $byDepartment[$group->departmentId][] = $group->id;
        }

        foreach ($input->subjectRoomDefaults as $default) {
            $roomDefaults["{$default->subjectCode}|{$default->campusCode}"][$default->roomId] = $default->priority;
        }

        $preferred = [];

        foreach ($input->teachers as $teacher) {
            $builder->teacher(new StateTeacher($teacher->id, 'El docente', $teacher->remainingHours + ($fixedHours[$teacher->id] ?? 0), 0,
                self::blocks($teacher->unavailable), $teacher->honorarios));
            $preferred[$teacher->id] = self::blocks($teacher->preferred);

            $byCampus = [];

            foreach ($teacher->busy as $slot) {
                $byCampus[$slot->campusCode ?? self::campusOfTeacher($input, $teacher->id)][] = $slot;
            }

            foreach ($byCampus as $campusCode => $slots) {
                self::context($builder, $input, self::CONTEXT."teacher:{$teacher->id}:{$campusCode}", (string) $campusCode, $teacher->id, null, $slots);
            }
        }

        $buildings = [];

        foreach ($input->rooms as $room) {
            $builder->room(new StateRoom($room->id, $room->campusCode, $room->id, $room->capacity, RoomType::tryFrom($room->type) ?? RoomType::Aula, $room->ownerDepartmentId));
            $buildings[$room->id] = $room->buildingId;

            foreach ($room->blocks as $block) {
                $builder->block(new StateRoomBlock($room->id, $block->day, Minutes::of($block->startsAt), Minutes::of($block->endsAt)));
            }

            if ($room->busy !== []) {
                self::context($builder, $input, self::CONTEXT."room:{$room->id}", $room->campusCode, null, $room->id, $room->busy);
            }
        }

        foreach ($input->agreements as $agreement) {
            foreach ($byDepartment[$agreement->toDepartmentId] ?? [] as $groupId) {
                $builder->loan(new StateRoomLoan($groupId, $agreement->roomId, $agreement->day, Minutes::of($agreement->startsAt), Minutes::of($agreement->endsAt), true));
            }
        }

        $movable = array_values(array_map(fn (GroupInput $group): string => $group->id, array_filter($input->groups, fn (GroupInput $group): bool => $group->movable)));

        return new self(
            $builder->build(),
            new ValidationScope($movable),
            new SoftContext($cohorts, $preferred, $buildings, $roomDefaults),
            $input->hardRules,
            $input->softWeights->weights,
            array_fill_keys(array_keys($groups), true),
        );
    }

    /**
     * Las sesiones que los grupos movibles ya tienen (`current_sessions`), como sesiones propuestas sin cambio: para
     * evaluar la carga actual y compararla con lo que propone el motor.
     *
     * @return list<ProposedSession>
     */
    public static function currentSessions(SolverInput $input): array
    {
        $sessions = [];

        foreach ($input->groups as $group) {
            if (! $group->movable) {
                continue;
            }

            foreach ($group->currentSessions as $slot) {
                $sessions[] = new ProposedSession($group->id, $slot->day, $slot->startsAt, $slot->endsAt, $slot->roomId, $slot->kind, false);
            }
        }

        return $sessions;
    }

    /**
     * La misma entrada con otra foto: la que devuelve un withSession() o withTeacher() de la heurística.
     */
    public function withState(ScheduleState $state): self
    {
        return new self($state, $this->scope, $this->context, $this->hardRules, $this->softWeights, $this->groupIds);
    }

    /**
     * Si el grupo es de la entrada (y no de contexto).
     */
    public function isInputGroup(string $groupId): bool
    {
        return isset($this->groupIds[$groupId]);
    }

    /**
     * La modalidad sintética de un campus de la entrada: la modalidad de la corrida con la rejilla de ese campus.
     */
    public static function modalityOf(SolverInput $input, string $campusCode): string
    {
        return "{$input->modalityCode}:{$campusCode}";
    }

    private static function subjectOf(GroupInput $group): string
    {
        return $group->subjectCode === '' ? "group:{$group->id}" : $group->subjectCode;
    }

    /**
     * Los semestres del alumno tipo de sus claves `plan:` y el paquete de su primera clave `package:`, como [id, número].
     *
     * @return array{list<array{string, string, string, int}>, array{string, int}|null}
     */
    private static function cohortsOf(SolverInput $input, GroupInput $group): array
    {
        $semesters = [];
        $package = null;

        foreach ($group->cohorts as $key) {
            $parts = explode(':', $key);

            if ($parts[0] === 'plan' && count($parts) === 4) {
                $semesters[] = [self::modalityOf($input, $parts[3]), $parts[3], $parts[1], (int) $parts[2]];
            } elseif ($parts[0] === 'package' && $package === null && in_array(count($parts), [3, 4], true)) {
                $package = [$parts[1], (int) $parts[count($parts) - 1]];
            }
        }

        return [$semesters, $package];
    }

    /**
     * @param  list<TimeSlot>  $slots
     * @return list<array{int, int, int}>
     */
    private static function blocks(array $slots): array
    {
        return array_map(fn (TimeSlot $slot): array => [$slot->day, Minutes::of($slot->startsAt), Minutes::of($slot->endsAt)], $slots);
    }

    private static function campusOfTeacher(SolverInput $input, string $teacherId): string
    {
        foreach ($input->groups as $group) {
            if ($group->teacherId === $teacherId) {
                return $group->campusCode;
            }
        }

        return $input->campuses[0]->code ?? '';
    }

    /**
     * Un grupo de contexto: la ocupación de un docente en un campus o de un salón, fuera del alcance y de todo semestre
     * del alumno tipo.
     *
     * @param  list<TimeSlot>  $slots
     */
    private static function context(ScheduleStateBuilder $builder, SolverInput $input, string $id, string $campusCode, ?string $teacherId, ?string $roomId, array $slots): void
    {
        $builder->group(new StateGroup($id, $campusCode, self::modalityOf($input, $campusCode), self::CONTEXT_SUBJECT, '', '', '', 0, '—', $teacherId, 0));
        $builder->typicalStudentSemesters($id, []);

        foreach ($slots as $n => $slot) {
            $builder->session(new StateSession("{$id}#{$n}", $id, $slot->day, Minutes::of($slot->startsAt), Minutes::of($slot->endsAt), $roomId));
        }
    }
}
