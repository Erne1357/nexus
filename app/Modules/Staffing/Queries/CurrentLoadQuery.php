<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Support\WeekGrid;
use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Models\ScheduleConflict;
use App\Modules\Staffing\Rules\SessionChips;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Lecturas de la pantalla «Carga actual» (SEL-18, tarea 1.57): los grupos de un departamento en un periodo y modalidad,
 * de todos sus campus o de uno (M1), agrupados por materia, con sus sesiones, salones, docente, cupo y choques duros
 * vigentes. Quién la ve lo decide AcademicLoadPermission::LoadView con el departamento; quién edita, las policies de
 * cada Action. Un número fijo de consultas sin importar cuántos grupos haya.
 */
final class CurrentLoadQuery
{
    /**
     * Los departamentos activos cuya carga puede ver el usuario en la modalidad, por clave.
     *
     * @return Collection<int, Department>
     */
    public function departmentsFor(Actor $actor, string $modalityId): Collection
    {
        return Department::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->filter(fn (Department $department): bool => $actor->allows(AcademicLoadPermission::LoadView, Target::of(modality: $modalityId, unit: $department->getKey())))
            ->values();
    }

    public function board(string $termId, string $modalityId, string $departmentId, ?string $campusId = null): CurrentLoad
    {
        /** @var Term $term */
        $term = KeyLookup::findOrFail(Term::query(), $termId);
        /** @var Modality $modality */
        $modality = KeyLookup::findOrFail(Modality::query()->withTrashed(), $modalityId);
        /** @var Campus|null $campus */
        $campus = $campusId === null ? null : KeyLookup::findOrFail(Campus::query()->withTrashed(), $campusId);
        /** @var Department $department */
        $department = KeyLookup::findOrFail(Department::query()->withTrashed(), $departmentId);
        $load = AcademicLoad::query()->ofDepartment($termId, $modalityId, $departmentId)->first();
        $profile = $modality->profile;

        $groups = $this->groupsQuery($termId, $modalityId, $departmentId, $campusId)
            ->with([
                'campus' => fn ($query) => $query->withTrashed()->select(['id', 'code', 'name']),
                'subject' => fn ($query) => $query->withTrashed(),
                'ownerCareer' => fn ($query) => $query->withTrashed()->select(['id', 'code', 'name']),
                'teacher' => fn ($query) => $query->withTrashed()->select(['id', 'name']),
                'sessions' => fn ($query) => $query->where('is_active', true)->with('room:id,code')->orderBy('day')->orderBy('starts_at'),
            ])
            ->get();

        $conflicts = $groups->isEmpty() ? collect() : ScheduleConflict::query()->open()
            ->where('severity', ConflictSeverity::Hard->value)
            ->whereIn('group_id', $groups->modelKeys())
            ->orderBy('rule')
            ->get(['group_id', 'rule', 'message'])
            ->groupBy('group_id');

        $rows = $groups->map(function (Group $group) use ($conflicts, $profile): CurrentLoadGroup {
            $sessions = $group->sessions;

            return new CurrentLoadGroup(
                group: $group,
                chips: SessionChips::of($sessions->map(fn ($session): array => [
                    'day' => $session->day,
                    'starts_at' => $session->starts_at,
                    'ends_at' => $session->ends_at,
                    'room' => $session->room?->code,
                    'virtual' => $session->kind === SessionKind::Virtual,
                ])),
                unscheduled: $profile->usesTimeBlocks && $sessions->isEmpty(),
                withoutRoom: $profile->usesRooms && $sessions->contains(fn ($session): bool => $session->room_id === null && $session->kind !== SessionKind::Virtual),
                conflicts: $conflicts->get($group->id, collect())->map(fn (ScheduleConflict $conflict): array => ['rule' => $conflict->rule, 'message' => $conflict->message])->values()->all(),
            );
        });

        return new CurrentLoad($term, $modality, $campus, $department, $load, $this->bySubject($rows));
    }

    /**
     * Un grupo no cancelado del departamento en el periodo y la modalidad (y el campus, si se filtra), por un id del
     * cliente; 404 si no es de ahí.
     */
    public function groupOrFail(string $termId, string $modalityId, string $departmentId, string $groupId, ?string $campusId = null): Group
    {
        /** @var Group */
        return KeyLookup::findOrFail($this->groupsQuery($termId, $modalityId, $departmentId, $campusId), $groupId);
    }

    /**
     * Una sesión del grupo por un id del cliente, con su salón (aunque esté desactivado); 404 si no es de ese grupo.
     */
    public function sessionOrFail(Group $group, string $sessionId): GroupSession
    {
        /** @var GroupSession */
        return KeyLookup::findOrFail(
            GroupSession::query()->where('group_id', $group->getKey())->with(['group', 'room' => fn ($relation) => $relation->withTrashed()]),
            $sessionId,
        );
    }

    /**
     * La retícula de bloques del perfil de la modalidad del grupo (aunque la modalidad esté desactivada).
     */
    public function gridOf(Group $group): WeekGrid
    {
        return WeekGrid::of($group->modality()->withTrashed()->firstOrFail()->profile);
    }

    /**
     * La carga del departamento del grupo en su periodo y modalidad; null si no hay.
     */
    public function loadOf(Group $group): ?AcademicLoad
    {
        return AcademicLoad::query()->ofDepartment($group->term_id, $group->modality_id, $group->department_id)->first();
    }

    /**
     * Los choques duros vigentes de un grupo (los mismos que muestra la lista), por regla.
     *
     * @return list<array{rule: string, message: string}>
     */
    public function conflictsOf(Group $group): array
    {
        return ScheduleConflict::query()->open()
            ->where('severity', ConflictSeverity::Hard->value)
            ->where('group_id', $group->getKey())
            ->orderBy('rule')
            ->get(['rule', 'message'])
            ->map(fn (ScheduleConflict $conflict): array => ['rule' => $conflict->rule, 'message' => $conflict->message])
            ->all();
    }

    /**
     * La materia de un id del cliente si algún grupo no cancelado del departamento, periodo y modalidad la tiene; si no,
     * 404 (no se edita la materia de otra carga).
     */
    public function subjectOrFail(string $termId, string $modalityId, string $departmentId, string $subjectId): Subject
    {
        /** @var Subject|null $subject */
        $subject = KeyLookup::find(
            Subject::query()->withTrashed()->whereIn('id', $this->groupsQuery($termId, $modalityId, $departmentId, null)->select('groups.subject_id')),
            $subjectId,
        );

        abort_if($subject === null, 404);

        return $subject;
    }

    /**
     * @return Builder<Group>
     */
    private function groupsQuery(string $termId, string $modalityId, string $departmentId, ?string $campusId): Builder
    {
        return Group::query()->active()
            ->where('groups.term_id', $termId)
            ->where('groups.modality_id', $modalityId)
            ->where('groups.department_id', $departmentId)
            ->when($campusId !== null, fn (Builder $query) => $query->where('groups.campus_id', $campusId));
    }

    /**
     * @param  Collection<int, CurrentLoadGroup>  $rows
     * @return list<CurrentLoadSubject>
     */
    private function bySubject(Collection $rows): array
    {
        return $rows->groupBy(fn (CurrentLoadGroup $row): string => $row->group->subject_id)
            ->map(fn (Collection $rows): CurrentLoadSubject => new CurrentLoadSubject(
                $rows->first()->group->subject,
                $rows->sortBy(fn (CurrentLoadGroup $row): array => [$row->group->semester, (string) $row->group->ownerCareer?->code, $row->group->code])->values()->all(),
            ))
            ->sortBy(fn (CurrentLoadSubject $subject): array => [
                (int) collect($subject->groups)->min(fn (CurrentLoadGroup $row): int => (int) $row->group->semester),
                Str::lower(Str::ascii((string) $subject->subject->name)),
                $subject->subject->id,
            ])
            ->values()
            ->all();
    }
}
