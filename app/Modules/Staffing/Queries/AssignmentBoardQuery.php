<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Enums\RankingState;
use App\Modules\Staffing\Enums\StaffingPermission;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Staffing\Models\StaffingRanking;
use App\Modules\Staffing\Rules\ScheduleSummary;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Lecturas de la pantalla «Asignación docente» (tarea 3.9): los grupos de un departamento en un periodo y campus, con
 * su horario, su docente vigente y los primeros lugares de su ranking (reglas-de-negocio.md, «Ranking docente
 * (desempate)», HC-16). Quién la ve lo decide StaffingRankingPolicy en el componente.
 */
final class AssignmentBoardQuery
{
    /** Cuántos lugares del ranking se muestran en el renglón de cada grupo. */
    public const int TOP = 3;

    public function __construct(private readonly GroupRankingQuery $rankings) {}

    /**
     * El tablero completo, con un número fijo de consultas sin importar cuántos grupos haya: el periodo, el campus,
     * el departamento y su carga; los grupos no cancelados con su materia, su carrera dueña, su docente y sus
     * sesiones; las asignaciones vigentes con quién las hizo; los primeros lugares de cada ranking con su docente;
     * el tamaño de cada ranking, y las plazas de esos docentes. Los grupos van por el semestre más bajo de su materia,
     * la materia, la carrera dueña y la clave.
     *
     * Un grupo sin renglones espera el ranking (RankingState::Pending) mientras el departamento no se haya recalculado
     * después de que el grupo nació; si ya se recalculó, nadie entró a su ranking (RankingState::Empty). El recálculo
     * reescribe de una vez todos los grupos del departamento (RankGroups).
     */
    public function board(string $termId, string $campusId, string $departmentId): AssignmentBoard
    {
        /** @var Term $term */
        $term = KeyLookup::findOrFail(Term::query(), $termId);
        /** @var Campus $campus */
        $campus = KeyLookup::findOrFail(Campus::query(), $campusId);
        /** @var Department $department */
        $department = KeyLookup::findOrFail(Department::query()->withTrashed(), $departmentId);
        $load = $this->load($termId, $campusId, $departmentId);

        $groups = $this->groupsQuery($termId, $campusId, $departmentId)
            ->with([
                'subject' => fn ($query) => $query->withTrashed()->select(['id', 'code', 'name']),
                'ownerCareer' => fn ($query) => $query->withTrashed()->select(['id', 'code', 'name']),
                'teacher' => fn ($query) => $query->withTrashed()->select(['id', 'name']),
                'sessions' => fn ($query) => $query->select(['id', 'group_id', 'day', 'starts_at', 'ends_at'])->orderBy('day')->orderBy('starts_at'),
            ])
            ->get();

        $ids = $groups->modelKeys();
        $assignments = $ids === [] ? collect() : GroupTeacherAssignment::query()->current()->whereIn('group_id', $ids)->with('assignedBy:id,name')->get()->keyBy('group_id');
        $top = $ids === [] ? collect() : StaffingRanking::query()
            ->whereIn('group_id', $ids)
            ->where('rank', '<=', self::TOP)
            ->with(['teacher' => fn ($query) => $query->withTrashed()->select(['id', 'name'])])
            ->orderBy('rank')
            ->get()
            ->groupBy('group_id');
        $stats = $ids === [] ? collect() : StaffingRanking::query()
            ->whereIn('group_id', $ids)
            ->groupBy('group_id')
            ->selectRaw('group_id, count(*) as size, max(computed_at) as computed_at')
            ->get()
            ->keyBy('group_id');
        $lastComputed = $stats->max(fn ($stat): string => (string) $stat->computed_at);
        $lastComputed = $lastComputed === null ? null : CarbonImmutable::parse($lastComputed);

        $positions = $this->rankings->positionsOf($term, $top->flatten()->pluck('teacher_id')
            ->merge($groups->pluck('teacher_id')->filter())
            ->unique()->values()->all());

        $rows = $this->sorted($groups)->map(function (Group $group) use ($assignments, $top, $stats, $lastComputed, $positions): BoardGroup {
            $summary = ScheduleSummary::of($group->sessions->map(fn ($session): array => [
                'day' => $session->day, 'starts_at' => $session->starts_at, 'ends_at' => $session->ends_at,
            ]));
            $size = (int) ($stats->get($group->id)?->size ?? 0);

            return new BoardGroup(
                group: $group,
                schedule: $summary->label(),
                hours: $summary->hoursLabel(),
                assignment: $assignments->get($group->id),
                teacherPositions: $group->teacher_id === null ? null : ($positions[$group->teacher_id] ?? 'Sin plaza'),
                candidates: $top->get($group->id, collect())->map(fn (StaffingRanking $ranking): RankingRow => GroupRankingQuery::row($ranking, $positions))->values()->all(),
                rankingSize: $size,
                rankingState: match (true) {
                    $size > 0 => RankingState::Ready,
                    $lastComputed === null || $lastComputed->lt($group->created_at) => RankingState::Pending,
                    default => RankingState::Empty,
                },
            );
        });

        return new AssignmentBoard($term, $campus, $department, $load, $rows->values()->all());
    }

    /**
     * La carga del departamento en el periodo y campus, o null si no tiene.
     */
    public function load(string $termId, string $campusId, string $departmentId): ?AcademicLoad
    {
        return AcademicLoad::query()
            ->where('term_id', $termId)
            ->where('campus_id', $campusId)
            ->where('department_id', $departmentId)
            ->first();
    }

    /**
     * Los departamentos activos cuyo ranking puede ver el usuario en el campus, por clave: el suyo para el jefe y el
     * jefe de proyecto docente; todos para Subdirección y el admin.
     *
     * @return Collection<int, Department>
     */
    public function departmentsFor(Actor $actor, Campus $campus): Collection
    {
        return Department::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->filter(fn (Department $department): bool => $actor->allows(StaffingPermission::RankingView, Target::of(campus: $campus->getKey(), unit: $department->getKey())))
            ->values();
    }

    /**
     * Un grupo no cancelado del departamento en el periodo y campus, por un id del cliente; 404 si no es de ahí.
     */
    public function groupOrFail(string $termId, string $campusId, string $departmentId, string $groupId): Group
    {
        /** @var Group */
        return KeyLookup::findOrFail($this->groupsQuery($termId, $campusId, $departmentId), $groupId);
    }

    /**
     * Como groupOrFail(), pero null si el grupo no es del departamento en el periodo y campus.
     */
    public function findGroup(string $termId, string $campusId, string $departmentId, string $groupId): ?Group
    {
        /** @var Group|null */
        return KeyLookup::find($this->groupsQuery($termId, $campusId, $departmentId), $groupId);
    }

    /**
     * Un docente por un id del cliente; 404 si no existe. Si está activo lo decide AssignTeacher.
     */
    public function teacherOrFail(string $teacherId): Teacher
    {
        /** @var Teacher */
        return KeyLookup::findOrFail(Teacher::query()->withTrashed(), $teacherId);
    }

    /**
     * @return Builder<Group>
     */
    private function groupsQuery(string $termId, string $campusId, string $departmentId): Builder
    {
        return Group::query()->active()
            ->where('groups.term_id', $termId)
            ->where('groups.campus_id', $campusId)
            ->where('groups.department_id', $departmentId);
    }

    /**
     * @param  Collection<int, Group>  $groups
     * @return Collection<int, Group>
     */
    private function sorted(Collection $groups): Collection
    {
        $firstSemester = $groups->groupBy('subject_id')->map(fn (Collection $rows): int => (int) $rows->min('semester'));

        return $groups->sortBy(fn (Group $group): array => [
            $firstSemester[$group->subject_id],
            Str::lower(Str::ascii((string) $group->subject?->name)),
            $group->subject_id,
            (string) $group->ownerCareer?->code,
            $group->code,
        ])->values();
    }
}
