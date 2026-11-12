<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Enums\SchedulingPermission;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Rules\LoadEditability;
use App\Modules\Scheduling\Validation\Clock;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\JoinClause;

/**
 * Lo que el editor de horarios pinta de un semestre reticular (tarea 3.5): los grupos sin cancelar del periodo y campus
 * de la carrera en ese plan y semestre, más los de otra carrera donde esta tiene cupo y cuya materia está en ese plan y
 * semestre (`shared`; de solo lectura para quien no puede editSessions). Van en el orden de la retícula (la posición de
 * la materia en el plan), luego por clave de materia y de grupo. Quien ve la carrera ve todos los grupos de su semestre
 * (HC-03 los necesita juntos): los que no ve por GroupPolicy::view van como contexto de solo lectura (`context`,
 * supuesto por confirmar).
 *
 * Por grupo: su materia (con el departamento que la imparte), su docente (id y nombre), capacidad, cupos (el dueño
 * primero), sesiones (por día y hora, con su salón y el departamento dueño del salón), las horas que pide su materia y
 * las que tiene colocadas, y lo que el actor que recibe puede hacer con su puesto activo, con los permisos y el
 * objetivo de GroupPolicy (`editSessions`, `assignRoom`, `manageQuotas`). Nadie puede nada de eso con un grupo de
 * contexto, ni si el grupo está cancelado o su carga no está en propuesta, asignación o ajustes: `editBlockedReason` dice
 * por qué, con el mensaje de LoadEditability::blockedReasons(), la misma regla que aplican las Actions. Además, las
 * violaciones del validador por grupo y, las que nombran una sesión, por sesión (una sola validación de todos los
 * grupos con la foto del periodo y campus); solo los grupos y sesiones que tienen alguna.
 *
 * No decide si el actor entra al semestre: eso es de la pantalla con PlanSemestersQuery::careerOrFail().
 */
final class SemesterBoardQuery
{
    public function __construct(
        private readonly ScheduleValidator $validator,
        private readonly LoadEditability $editability,
    ) {}

    /**
     * @return array{
     *     groups: list<array{
     *         id: string,
     *         code: string,
     *         status: GroupStatus,
     *         shared: bool,
     *         context: bool,
     *         subject: array{id: string, code: string, name: string, theory_hours: int, practice_hours: int, needs_lab: bool, department_code: ?string, department_name: ?string},
     *         teacher_id: ?string,
     *         teacher: ?string,
     *         capacity: int,
     *         quotas: list<array{career_id: string, career_code: ?string, seats: int, owner: bool}>,
     *         sessions: list<array{id: string, day: int, starts_at: string, ends_at: string, kind: SessionKind, room: ?array{id: string, code: string, department_id: ?string, department_code: ?string}}>,
     *         required_hours: int,
     *         placed_hours: int|float,
     *         can: array{editSessions: bool, assignRoom: bool, manageQuotas: bool},
     *         editBlockedReason: ?string,
     *     }>,
     *     violations: array{groups: array<string, list<Violation>>, sessions: array<string, list<Violation>>},
     * }
     */
    public function for(Term $term, Campus $campus, Career $career, StudyPlan $plan, int $semester, Actor $actor): array
    {
        $withTrashed = fn (Relation $query) => $query->withTrashed();

        $groups = Group::query()
            ->select('groups.*')
            ->join('subjects', 'subjects.id', '=', 'groups.subject_id')
            ->leftJoin('study_plan_subjects as placement', fn (JoinClause $join) => $join
                ->on('placement.subject_id', '=', 'groups.subject_id')
                ->where('placement.study_plan_id', $plan->id)
                ->whereNull('placement.deleted_at'))
            ->active()
            ->where('groups.term_id', $term->id)
            ->where('groups.campus_id', $campus->id)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $own) => $own->where('groups.owner_career_id', $career->id)->where('groups.study_plan_id', $plan->id)->where('groups.semester', $semester))
                ->orWhere(fn (Builder $shared) => $shared->where('groups.owner_career_id', '<>', $career->id)
                    ->where('placement.semester', $semester)
                    ->whereExists(GroupQuota::query()->toBase()->whereColumn('group_quotas.group_id', 'groups.id')->where('group_quotas.career_id', $career->id))))
            ->with([
                'subject' => $withTrashed,
                'subject.department' => $withTrashed,
                'teacher' => $withTrashed,
                'quotas.career' => $withTrashed,
                'sessions' => fn (Relation $query) => $query->orderBy('day')->orderBy('starts_at'),
                'sessions.room' => $withTrashed,
                'sessions.room.department' => $withTrashed,
            ])
            ->orderByRaw('placement.position nulls last')
            ->orderBy('subjects.code')
            ->orderBy('groups.code')
            ->get();

        $blocked = $this->editability->blockedReasons($groups);
        $byGroup = [];
        $bySession = [];

        if ($groups->isNotEmpty()) {
            $result = $this->validator->validate($this->validator->stateFor($term->id, $campus->id), ValidationScope::groups(...$groups->modelKeys()));

            foreach ($groups as $group) {
                foreach ($result->forGroup($group->id) as $violation) {
                    $byGroup[$group->id][] = $violation;
                    $sessionId = $violation->related['session_id'] ?? null;

                    if (is_string($sessionId)) {
                        $bySession[$sessionId][] = $violation;
                    }
                }
            }
        }

        $abilities = $groups->mapWithKeys(fn (Group $group): array => [$group->id => self::abilities($actor, $group, $blocked[$group->id])]);

        return [
            'groups' => $groups->map(fn (Group $group): array => [
                'id' => $group->id,
                'code' => $group->code,
                'status' => $group->status,
                'shared' => $group->owner_career_id !== $career->id,
                'context' => $abilities[$group->id]['context'],
                'subject' => [
                    'id' => $group->subject->id,
                    'code' => $group->subject->code,
                    'name' => $group->subject->name,
                    'theory_hours' => $group->subject->theory_hours,
                    'practice_hours' => $group->subject->practice_hours,
                    'needs_lab' => $group->subject->needs_lab,
                    'department_code' => $group->subject->department?->code,
                    'department_name' => $group->subject->department?->name,
                ],
                'teacher_id' => $group->teacher_id,
                'teacher' => $group->teacher?->name,
                'capacity' => $group->capacity,
                'quotas' => $group->quotas
                    ->sortBy(fn (GroupQuota $quota): string => ($quota->is_owner ? '0' : '1').$quota->career?->code)
                    ->map(fn (GroupQuota $quota): array => [
                        'career_id' => $quota->career_id,
                        'career_code' => $quota->career?->code,
                        'seats' => $quota->max_seats,
                        'owner' => $quota->is_owner,
                    ])->values()->all(),
                'sessions' => $group->sessions->map(fn (GroupSession $session): array => [
                    'id' => $session->id,
                    'day' => $session->day,
                    'starts_at' => Clock::of($session->starts_at),
                    'ends_at' => Clock::of($session->ends_at),
                    'kind' => $session->kind,
                    'room' => $session->room === null ? null : [
                        'id' => $session->room->id,
                        'code' => $session->room->code,
                        'department_id' => $session->room->department_id,
                        'department_code' => $session->room->department?->code,
                    ],
                ])->all(),
                'required_hours' => $group->subject->theory_hours + $group->subject->practice_hours,
                'placed_hours' => $group->sessions->sum(fn (GroupSession $session): int => (int) $session->minutes) / 60,
                'can' => $abilities[$group->id]['can'],
                'editBlockedReason' => $blocked[$group->id],
            ])->all(),
            'violations' => ['groups' => $byGroup, 'sessions' => $bySession],
        ];
    }

    /**
     * Lo que el actor puede con el grupo, con su puesto activo: los permisos y el objetivo de GroupPolicy (la modalidad, el campus, el
     * departamento y la carrera dueña del grupo). Un grupo que no ve es contexto, y con la edición bloqueada no puede
     * nada.
     *
     * @return array{context: bool, can: array{editSessions: bool, assignRoom: bool, manageQuotas: bool}}
     */
    private static function abilities(Actor $actor, Group $group, ?string $blockedReason): array
    {
        $read = Target::of(modality: $group->modality_id, unit: $group->department_id, career: $group->owner_career_id);
        $target = Target::of(modality: $group->modality_id, campus: $group->campus_id, unit: $group->department_id, career: $group->owner_career_id);
        $context = ! $actor->allows(SchedulingPermission::ScheduleView, $read);
        $open = ! $context && $blockedReason === null;

        return [
            'context' => $context,
            'can' => [
                'editSessions' => $open && $actor->allows(SchedulingPermission::SessionEdit, $target),
                'assignRoom' => $open && $actor->allows(SchedulingPermission::RoomAssign, $target),
                'manageQuotas' => $open && $actor->allows(SchedulingPermission::QuotaManage, $target),
            ],
        ];
    }
}
