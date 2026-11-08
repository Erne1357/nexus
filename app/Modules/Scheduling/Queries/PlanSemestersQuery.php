<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Scheduling\Enums\SchedulingPermission;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * La navegación del editor de horarios (tarea 3.5): carrera → retícula (plan) → semestre, en un periodo y campus
 * (reglas-de-negocio.md, "Editor de horarios").
 *
 * - careers(): las carreras activas que se ofrecen en el campus (`career_campus` activa) y que el actor ve con
 *   «Ver los horarios» (SchedulingPermission::ScheduleView) sobre la carrera: su campus, su departamento como unidad y
 *   ella misma. Además, como supuesto por confirmar, las carreras donde el actor ve algún grupo activo del periodo y
 *   campus (el jefe de un departamento que imparte en otras carreras, como Ciencias Básicas, llega así a sus grupos).
 * - plans(): los planes activos de la carrera, el vigente primero, con cuántos grupos tienen en el periodo y campus y
 *   cuántos tienen horas sin acomodar.
 * - semesters(): los semestres de un plan, del 1 al último, con los mismos conteos.
 *
 * Los conteos son de los grupos de la carrera en ese plan (no los compartidos de otras carreras), sin cancelados. Un
 * grupo tiene horas sin acomodar si sus sesiones suman menos que las horas de su materia (teoría + práctica).
 */
final class PlanSemestersQuery
{
    /**
     * @return list<array{id: string, code: string, name: string}>
     */
    public function careers(Actor $actor, Term $term, Campus $campus): array
    {
        return $this->visibleCareers($actor, $term, $campus)
            ->orderBy('careers.code')
            ->get(['careers.id', 'careers.code', 'careers.name'])
            ->map(fn (Career $career): array => ['id' => $career->id, 'code' => $career->code, 'name' => $career->name])
            ->all();
    }

    /**
     * La carrera, si el actor la ve en el campus (careers()); el id llega del cliente.
     */
    public function careerOrFail(Actor $actor, Term $term, Campus $campus, string $careerId): Career
    {
        return KeyLookup::findOrFail($this->visibleCareers($actor, $term, $campus), $careerId);
    }

    /**
     * @return list<array{id: string, code: string, name: string, is_current: bool, is_priority: bool, groups: int, unscheduled: int}>
     */
    public function plans(Career $career, Term $term, Campus $campus): array
    {
        $counts = $this->counts($term, $campus, $career->id)
            ->groupBy('groups.study_plan_id')
            ->addSelect('groups.study_plan_id as plan_id')
            ->get()
            ->keyBy('plan_id');

        return StudyPlan::query()
            ->where('career_id', $career->id)
            ->where('is_active', true)
            ->orderByDesc('is_current')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'is_current'])
            ->map(fn (StudyPlan $plan): array => [
                'id' => $plan->id,
                'code' => $plan->code,
                'name' => $plan->name,
                'is_current' => $plan->is_current,
                'is_priority' => ! $plan->is_current,
                'groups' => (int) ($counts[$plan->id]->groups ?? 0),
                'unscheduled' => (int) ($counts[$plan->id]->unscheduled ?? 0),
            ])
            ->all();
    }

    /**
     * Un plan activo de la carrera; el id llega del cliente.
     */
    public function planOrFail(Career $career, string $planId): StudyPlan
    {
        return KeyLookup::findOrFail(StudyPlan::query()->where('career_id', $career->id)->where('is_active', true), $planId);
    }

    /**
     * Del semestre 1 al último del plan: `semesters_count`, o sin él el último de su retícula o de sus grupos.
     *
     * @return list<array{semester: int, groups: int, unscheduled: int}>
     */
    public function semesters(StudyPlan $plan, Term $term, Campus $campus): array
    {
        $counts = $this->counts($term, $campus, $plan->career_id)
            ->where('groups.study_plan_id', $plan->id)
            ->groupBy('groups.semester')
            ->addSelect('groups.semester')
            ->get()
            ->keyBy('semester');

        $last = max(
            (int) $plan->semesters_count,
            (int) StudyPlanSubject::query()->where('study_plan_id', $plan->id)->max('semester'),
            (int) $counts->keys()->max(),
        );

        return array_map(fn (int $semester): array => [
            'semester' => $semester,
            'groups' => (int) ($counts[$semester]->groups ?? 0),
            'unscheduled' => (int) ($counts[$semester]->unscheduled ?? 0),
        ], $last === 0 ? [] : range(1, $last));
    }

    /**
     * @return Builder<Career>
     */
    private function visibleCareers(Actor $actor, Term $term, Campus $campus): Builder
    {
        $byCareer = $actor->constrain(
            CareerCampus::query()
                ->join('careers as offered', 'offered.id', '=', 'career_campus.career_id')
                ->where('career_campus.campus_id', $campus->id)
                ->select('career_campus.career_id'),
            SchedulingPermission::ScheduleView,
            ['campus' => 'career_campus.campus_id', 'unit' => 'offered.department_id', 'career' => 'career_campus.career_id'],
        );

        $byGroups = $actor->constrain(
            Group::query()->active()->where('groups.term_id', $term->id)->where('groups.campus_id', $campus->id)->select('groups.owner_career_id'),
            SchedulingPermission::ScheduleView,
            ['campus' => 'groups.campus_id', 'unit' => 'groups.department_id', 'career' => 'groups.owner_career_id'],
        );

        return Career::query()
            ->where('careers.is_active', true)
            ->whereIn('careers.id', CareerCampus::query()->where('campus_id', $campus->id)->where('is_active', true)->select('career_id'))
            ->where(fn (Builder $visible) => $visible->whereIn('careers.id', $byCareer)->orWhereIn('careers.id', $byGroups));
    }

    /**
     * Los grupos sin cancelar de la carrera en el periodo y campus, con cuántos tienen horas sin acomodar; quien llama
     * agrupa.
     */
    private function counts(Term $term, Campus $campus, string $careerId): QueryBuilder
    {
        $placed = GroupSession::query()->toBase()
            ->where('term_id', $term->id)
            ->where('campus_id', $campus->id)
            ->groupBy('group_id')
            ->selectRaw('group_id, sum(extract(epoch from ends_at - starts_at)) / 60 as minutes');

        return Group::query()->active()->toBase()
            ->join('subjects', 'subjects.id', '=', 'groups.subject_id')
            ->leftJoinSub($placed, 'placed', 'placed.group_id', '=', 'groups.id')
            ->where('groups.term_id', $term->id)
            ->where('groups.campus_id', $campus->id)
            ->where('groups.owner_career_id', $careerId)
            ->selectRaw('count(*) as groups')
            ->selectRaw('count(*) filter (where coalesce(placed.minutes, 0) < (subjects.theory_hours + subjects.practice_hours) * 60) as unscheduled');
    }
}
