<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Scheduling\Actions\SyncScheduleConflicts;
use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Enums\SchedulingPermission;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Models\ScheduleConflict;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Lo que lee el tablero de choques y avance (tarea 3.4): qué le falta a cada grupo activo de un campus en un periodo
 * para cerrar la carga (HC-15), contado por carrera, retícula y semestre (la vista principal, decidida por el usuario
 * el 7 de noviembre de 2026) y por departamento o carrera (la vista de matriz).
 *
 * A un grupo le falta algo si tiene un choque duro vigente en `schedule_conflicts`, si no tiene sesiones (solo en un
 * campus con bloques de tiempo), si no tiene docente o su docente está dado de baja (P10), o si alguna sesión presencial no tiene salón (solo en un campus
 * que usa salones). Solo cuenta lo que el usuario puede ver (`scheduling.schedule.view` con su alcance: el jefe, su
 * departamento; el coordinador, su carrera; en toda la modalidad aunque el puesto tenga un campus, MS6), filtrado en SQL con `Actor::constrain()`. Lee el estado de
 * todos los grupos del alcance en una consulta y cuenta en memoria: una carga de un campus son cientos de grupos.
 *
 * Un grupo compartido (con cupo de otra carrera) cuenta también en esa carrera, en cada retícula suya donde está la
 * materia y en el semestre que ahí tiene, como en el editor de horarios; y el coordinador de esa carrera lo ve. En
 * cada carrera un grupo cuenta una vez; los totales del campus y por departamento no lo repiten.
 */
final class ConflictBoardQuery
{
    /**
     * El estado de cada grupo activo del alcance.
     *
     * @return Collection<int, GroupBoardStatus>
     */
    public function statuses(Actor $actor, Term $term, Campus $campus): Collection
    {
        $profile = $campus->profile;
        $shared = $this->sharedPlacements($term, $campus);

        return $this->scoped($actor, $term, $campus)
            ->select(['groups.id', 'groups.code', 'groups.subject_id', 'groups.department_id', 'groups.owner_career_id', 'groups.study_plan_id', 'groups.semester', 'groups.teacher_id'])
            ->selectRaw('exists (select 1 from group_sessions s where s.group_id = groups.id and s.is_active) as has_sessions')
            ->selectRaw('exists (select 1 from group_sessions s where s.group_id = groups.id and s.room_id is null and s.kind <> ?) as missing_room', [SessionKind::Virtual->value])
            ->selectRaw('exists (select 1 from schedule_conflicts c where c.group_id = groups.id and c.resolved_at is null and c.severity = ?) as has_hard', [ConflictSeverity::Hard->value])
            ->selectRaw('exists (select 1 from teachers t where t.id = groups.teacher_id and (not t.is_active or t.deleted_at is not null)) as inactive_teacher')
            ->orderBy('groups.semester')->orderBy('groups.code')
            ->toBase()->get()
            ->map(fn (object $row): GroupBoardStatus => new GroupBoardStatus(
                id: $row->id,
                code: $row->code,
                subjectId: $row->subject_id,
                departmentId: $row->department_id,
                careerId: $row->owner_career_id,
                studyPlanId: $row->study_plan_id,
                semester: (int) $row->semester,
                hard: (bool) $row->has_hard,
                unscheduled: $profile->usesTimeBlocks && ! $row->has_sessions,
                withoutTeacher: $row->teacher_id === null || (bool) $row->inactive_teacher,
                withoutRoom: $profile->usesRooms && (bool) $row->missing_room,
                placements: [
                    ['career' => $row->owner_career_id, 'plan' => $row->study_plan_id, 'semester' => (int) $row->semester],
                    ...$shared[$row->id] ?? [],
                ],
                inactiveTeacher: (bool) $row->inactive_teacher,
            ));
    }

    /**
     * Los totales de la carga que el usuario ve.
     *
     * @param  Collection<int, GroupBoardStatus>  $statuses
     */
    public function summary(Collection $statuses): BoardCount
    {
        return BoardCount::of($statuses);
    }

    /**
     * Un renglón por departamento con grupos en el alcance, por clave.
     *
     * @param  Collection<int, GroupBoardStatus>  $statuses
     * @return list<array{id: string, code: string, name: string, count: BoardCount}>
     */
    public function byDepartment(Collection $statuses): array
    {
        $departments = Department::query()->withTrashed()->whereIn('id', $statuses->pluck('departmentId')->unique()->all())->get(['id', 'code', 'name']);

        return $this->rows($departments, $statuses->groupBy('departmentId'));
    }

    /**
     * Un renglón por carrera con grupos en el alcance (dueña o con cupo), por clave.
     *
     * @param  Collection<int, GroupBoardStatus>  $statuses
     * @return list<array{id: string, code: string, name: string, count: BoardCount}>
     */
    public function byCareer(Collection $statuses): array
    {
        $grouped = [];

        foreach ($statuses as $status) {
            foreach (array_unique(array_column($status->placements, 'career')) as $careerId) {
                $grouped[$careerId][] = $status;
            }
        }

        $careers = Career::query()->withTrashed()->whereIn('id', array_keys($grouped))->get(['id', 'code', 'name']);

        return $this->rows($careers, collect($grouped)->map(fn (array $ofCareer): Collection => new Collection($ofCareer)));
    }

    /**
     * La cuadrícula de una carrera: sus retículas con grupos (primero las viejas, que llevan «Prioridad» porque ya no
     * entran alumnos a ellas) por semestre, con lo que le falta a cada celda.
     *
     * @param  Collection<int, GroupBoardStatus>  $statuses
     * @return array{plans: list<array{id: string, code: string, name: string, priority: bool, cells: array<int, BoardCount>}>, semesters: int}
     */
    public function grid(Collection $statuses, string $careerId): array
    {
        $ofCareer = $statuses->filter(fn (GroupBoardStatus $status): bool => $status->countsFor($careerId));
        $cells = $ofCareer->flatMap(fn (GroupBoardStatus $status): array => array_values(array_filter(
            $status->placements,
            fn (array $placement): bool => $placement['career'] === $careerId,
        )));
        $plans = StudyPlan::query()->withTrashed()->whereIn('id', $cells->pluck('plan')->unique()->all())
            ->orderBy('is_current')->orderBy('code')
            ->get(['id', 'code', 'name', 'is_current', 'semesters_count']);

        $semesters = max([1, (int) $plans->max('semesters_count'), (int) $cells->max('semester')]);

        return [
            'semesters' => $semesters,
            'plans' => $plans->map(fn (StudyPlan $plan): array => [
                'id' => $plan->id,
                'code' => $plan->code,
                'name' => $plan->name,
                'priority' => ! $plan->is_current,
                'cells' => $cells->where('plan', $plan->id)->pluck('semester')->unique()->sort()
                    ->mapWithKeys(fn (int $semester): array => [$semester => BoardCount::of(
                        $ofCareer->filter(fn (GroupBoardStatus $status): bool => $status->placedIn($careerId, $plan->id, $semester)),
                    )])->all(),
            ])->values()->all(),
        ];
    }

    /**
     * Lo que le falta a un conjunto de grupos, para el detalle: sus choques duros vigentes y los grupos sin horario,
     * sin docente y sin salón, con la clave y el nombre de su materia.
     *
     * @param  Collection<int, GroupBoardStatus>  $statuses
     *                                                       Los choques van agrupados por grupo (un grupo con varios choques es una sola entrada), por semestre, materia y
     *                                                       clave.
     * @return array{conflicts: list<array{group: array<string, mixed>, items: list<array{id: string, rule: string, message: string}>}>, unscheduled: list<array<string, mixed>>, withoutTeacher: list<array<string, mixed>>, withoutRoom: list<array<string, mixed>>}
     */
    public function detail(Collection $statuses): array
    {
        $missing = $statuses->filter(fn (GroupBoardStatus $status): bool => $status->isMissing());
        $groups = Group::query()->whereIn('groups.id', $missing->pluck('id')->all())
            ->join('subjects', 'subjects.id', '=', 'groups.subject_id')
            ->join('careers', 'careers.id', '=', 'groups.owner_career_id')
            ->get(['groups.id', 'groups.code', 'groups.semester', 'subjects.code as subject_code', 'subjects.name as subject_name', 'careers.code as career_code'])
            ->keyBy('id');

        $describe = fn (GroupBoardStatus $status): array => [
            'id' => $status->id,
            'code' => $status->code,
            'semester' => $status->semester,
            'subject_code' => $groups[$status->id]->subject_code ?? '',
            'subject_name' => $groups[$status->id]->subject_name ?? '',
            'career_code' => $groups[$status->id]->career_code ?? '',
            'inactive_teacher' => $status->inactiveTeacher,
        ];

        $conflicts = ScheduleConflict::query()->open()
            ->where('severity', ConflictSeverity::Hard)
            ->whereIn('group_id', $missing->where('hard', true)->pluck('id')->all())
            ->orderBy('rule')->orderBy('created_at')
            ->get(['id', 'group_id', 'rule', 'message'])
            ->groupBy('group_id')
            ->map(fn (Collection $ofGroup, string $groupId): array => [
                'group' => $describe($missing->firstWhere('id', $groupId)),
                'items' => $ofGroup->map(fn (ScheduleConflict $conflict): array => [
                    'id' => $conflict->id,
                    'rule' => $conflict->rule,
                    'message' => $conflict->message,
                ])->values()->all(),
            ])
            ->sortBy(fn (array $entry): string => $entry['group']['semester'].'|'.$entry['group']['subject_code'].'|'.$entry['group']['code'])
            ->values()->all();

        return [
            'conflicts' => $conflicts,
            'unscheduled' => $missing->where('unscheduled', true)->map($describe)->values()->all(),
            'withoutTeacher' => $missing->where('withoutTeacher', true)->map($describe)->values()->all(),
            'withoutRoom' => $missing->where('withoutRoom', true)->map($describe)->values()->all(),
        ];
    }

    /**
     * Cuándo se recalcularon por última vez los choques de la carga del campus; null si nunca.
     */
    public function lastSyncedAt(Term $term, Campus $campus): ?CarbonImmutable
    {
        $at = Cache::get(SyncScheduleConflicts::stampKey($term->id, $campus->id));

        return $at === null ? null : CarbonImmutable::parse($at)->setTimezone(config('app.timezone'));
    }

    /**
     * Si el usuario ve grupos activos de ese departamento en el campus y periodo.
     */
    public function hasDepartment(Actor $actor, Term $term, Campus $campus, string $departmentId): bool
    {
        return $this->scoped($actor, $term, $campus)->where('groups.department_id', $departmentId)->exists();
    }

    /**
     * Los grupos activos del campus y periodo que el usuario puede ver.
     *
     * @return Builder<Group>
     */
    private function scoped(Actor $actor, Term $term, Campus $campus): Builder
    {
        $query = Group::query()
            ->where('groups.term_id', $term->id)
            ->where('groups.campus_id', $campus->id)
            ->where('groups.status', '<>', GroupStatus::Cancelled->value);

        return $actor->constrain($query, SchedulingPermission::ScheduleView, [
            'modality' => 'groups.modality_id',
            'unit' => 'groups.department_id',
            'career' => fn (Builder $careers, array $ids) => $careers->whereIn('groups.owner_career_id', $ids)
                ->orWhereExists(GroupQuota::query()->toBase()->whereColumn('group_quotas.group_id', 'groups.id')->whereIn('group_quotas.career_id', $ids)),
        ]);
    }

    /**
     * Dónde cuenta cada grupo compartido del campus y periodo en las carreras que le dan cupo: cada retícula de esa
     * carrera donde está la materia, con el semestre que ahí tiene.
     *
     * @return array<string, list<array{career: string, plan: string, semester: int}>>
     */
    private function sharedPlacements(Term $term, Campus $campus): array
    {
        $placements = [];

        GroupQuota::query()
            ->join('groups', 'groups.id', '=', 'group_quotas.group_id')
            ->join('study_plans', fn ($join) => $join->on('study_plans.career_id', '=', 'group_quotas.career_id')->whereNull('study_plans.deleted_at'))
            ->join('study_plan_subjects', fn ($join) => $join->on('study_plan_subjects.study_plan_id', '=', 'study_plans.id')
                ->on('study_plan_subjects.subject_id', '=', 'groups.subject_id')
                ->whereNull('study_plan_subjects.deleted_at'))
            ->where('groups.term_id', $term->id)
            ->where('groups.campus_id', $campus->id)
            ->where('groups.status', '<>', GroupStatus::Cancelled->value)
            ->whereColumn('group_quotas.career_id', '<>', 'groups.owner_career_id')
            ->orderBy('study_plans.code')
            ->toBase()
            ->get(['group_quotas.group_id', 'group_quotas.career_id', 'study_plan_subjects.study_plan_id', 'study_plan_subjects.semester'])
            ->each(function (object $row) use (&$placements): void {
                $placements[$row->group_id][] = ['career' => $row->career_id, 'plan' => $row->study_plan_id, 'semester' => (int) $row->semester];
            });

        return $placements;
    }

    /**
     * @param  Collection<int, Department|Career>  $owners
     * @param  Collection<string, Collection<int, GroupBoardStatus>>  $grouped
     * @return list<array{id: string, code: string, name: string, count: BoardCount}>
     */
    private function rows(Collection $owners, Collection $grouped): array
    {
        return $owners->sortBy('code')->values()
            ->map(fn (Department|Career $owner): array => [
                'id' => $owner->id,
                'code' => $owner->code,
                'name' => $owner->name,
                'count' => BoardCount::of($grouped->get($owner->id, new Collection)),
            ])->all();
    }
}
