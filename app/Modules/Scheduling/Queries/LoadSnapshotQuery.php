<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Models\GroupSession;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Los grupos de la foto de una carga al publicarla (tarea 4.3), con la forma de `groups` en
 * `AcademicLoad\Rules\SnapshotPayload` (docs/dominio/modelo-de-datos.md, «Contenido del snapshot»): los grupos no
 * cancelados del departamento en el campus y el periodo, con su materia, su docente, dónde cuentan en cada carrera y
 * sus sesiones con salón y edificio. Congela lo que hoy se calcula y cambiaría después: la colocación de cada carrera
 * con cupo (desde la retícula del momento), la clave final y los nombres.
 *
 * Lee también lo borrado (materia, docente, carreras, retículas, salones y edificios): la carga ya pasó la guarda de
 * HC-15 y la foto dice lo que había, no lo que hay. Un número fijo de consultas, sin importar cuántos grupos tenga el
 * departamento.
 */
final class LoadSnapshotQuery
{
    /**
     * @return list<array<string, mixed>>
     */
    public function groups(AcademicLoad $load): array
    {
        $trashed = fn (Relation $query) => $query->withTrashed();

        $groups = Group::query()
            ->where('term_id', $load->term_id)
            ->where('campus_id', $load->campus_id)
            ->where('department_id', $load->department_id)
            ->where('status', '<>', GroupStatus::Cancelled->value)
            ->with([
                'subject' => $trashed,
                'teacher' => $trashed,
                'ownerCareer' => $trashed,
                'studyPlan' => $trashed,
                'quotas.career' => $trashed,
                'sessions' => fn (Relation $query) => $query->orderBy('day')->orderBy('starts_at'),
                'sessions.room' => $trashed,
                'sessions.room.building' => $trashed,
            ])
            ->get();

        $shared = $this->sharedPlacements($groups->pluck('id')->all());

        return $groups
            ->sortBy(fn (Group $group): string => $group->subject->code."\0".$group->code)
            ->map(fn (Group $group): array => [
                'id' => $group->id,
                'code' => $group->code,
                'status' => $group->status->value,
                'capacity' => $group->capacity,
                'justification' => null,
                'subject' => [
                    'id' => $group->subject->id,
                    'code' => $group->subject->code,
                    'name' => $group->subject->name,
                    'credits' => $group->subject->credits,
                    'theory_hours' => $group->subject->theory_hours,
                    'practice_hours' => $group->subject->practice_hours,
                    'department_id' => $group->subject->department_id,
                ],
                'teacher' => $group->teacher === null ? null : ['id' => $group->teacher->id, 'rfc' => $group->teacher->rfc, 'name' => $group->teacher->name],
                'placements' => $this->placements($group, $shared[$group->id] ?? []),
                'sessions' => $group->sessions->map(fn (GroupSession $session): array => [
                    'day' => $session->day,
                    'starts_at' => substr($session->starts_at, 0, 5),
                    'ends_at' => substr($session->ends_at, 0, 5),
                    'kind' => $session->kind->value,
                    'kind_label' => $session->kind->label(),
                    'room' => $session->room === null ? null : [
                        'id' => $session->room->id,
                        'code' => $session->room->code,
                        'name' => $session->room->name,
                        'building' => ['id' => $session->room->building->id, 'code' => $session->room->building->code, 'name' => $session->room->building->name],
                        'owner_department_id' => $session->room->department_id,
                    ],
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * La carrera dueña primero, con el plan y el semestre del grupo y los lugares de su cupo de dueña (cero si no lo
     * tiene); después, por cada carrera con cupo, una entrada por retícula que tiene la materia, o una sin retícula si
     * ninguna la tiene.
     *
     * @param  list<array{plan_id: string, plan_code: string, semester: int, career_id: string}>  $shared
     * @return list<array<string, mixed>>
     */
    private function placements(Group $group, array $shared): array
    {
        $career = fn ($career): array => ['id' => $career->id, 'code' => $career->code, 'name' => $career->name];
        $owner = $group->quotas->first(fn (GroupQuota $quota): bool => $quota->is_owner);

        $placements = [[
            'career' => $career($group->ownerCareer),
            'study_plan' => ['id' => $group->studyPlan->id, 'code' => $group->studyPlan->code],
            'semester' => $group->semester,
            'seats' => $owner?->max_seats ?? 0,
            'is_owner' => true,
        ]];

        $others = $group->quotas
            ->reject(fn (GroupQuota $quota): bool => $quota->is_owner || $quota->career_id === $group->owner_career_id)
            ->sortBy(fn (GroupQuota $quota): string => $quota->career->code);

        foreach ($others as $quota) {
            $plans = array_values(array_filter($shared, fn (array $row): bool => $row['career_id'] === $quota->career_id));

            foreach ($plans === [] ? [null] : $plans as $plan) {
                $placements[] = [
                    'career' => $career($quota->career),
                    'study_plan' => $plan === null ? null : ['id' => $plan['plan_id'], 'code' => $plan['plan_code']],
                    'semester' => $plan['semester'] ?? null,
                    'seats' => $quota->max_seats,
                    'is_owner' => false,
                ];
            }
        }

        return $placements;
    }

    /**
     * Cada retícula vigente de una carrera con cupo (no la dueña) que tiene la materia del grupo, con su semestre; la
     * misma cuenta que el tablero de choques («sharedPlacements»), por clave de retícula.
     *
     * @param  list<string>  $groupIds
     * @return array<string, list<array{plan_id: string, plan_code: string, semester: int, career_id: string}>>
     */
    private function sharedPlacements(array $groupIds): array
    {
        $placements = [];

        GroupQuota::query()
            ->join('groups', 'groups.id', '=', 'group_quotas.group_id')
            ->join('study_plans', fn ($join) => $join->on('study_plans.career_id', '=', 'group_quotas.career_id')->whereNull('study_plans.deleted_at'))
            ->join('study_plan_subjects', fn ($join) => $join->on('study_plan_subjects.study_plan_id', '=', 'study_plans.id')
                ->on('study_plan_subjects.subject_id', '=', 'groups.subject_id')
                ->whereNull('study_plan_subjects.deleted_at'))
            ->whereIn('group_quotas.group_id', $groupIds)
            ->whereColumn('group_quotas.career_id', '<>', 'groups.owner_career_id')
            ->orderBy('study_plans.code')
            ->toBase()
            ->get(['group_quotas.group_id', 'group_quotas.career_id', 'study_plans.id as plan_id', 'study_plans.code as plan_code', 'study_plan_subjects.semester'])
            ->each(function (object $row) use (&$placements): void {
                $placements[$row->group_id][] = ['plan_id' => $row->plan_id, 'plan_code' => $row->plan_code, 'semester' => (int) $row->semester, 'career_id' => $row->career_id];
            });

        return $placements;
    }
}
