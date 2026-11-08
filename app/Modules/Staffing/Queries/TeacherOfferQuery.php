<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Enums\QualificationLevel;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Staffing\Models\StaffingRanking;
use App\Modules\Staffing\Rules\RankingWeights;
use App\Modules\Staffing\Support\SessionLabels;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Oferta del departamento para un docente (HC-17, tarea 3.10): todos los grupos no cancelados de cada departamento
 * donde tiene una adscripción vigente, en todos los campus, desde que la carga del departamento (periodo, campus y
 * departamento) entra a `staffing` y en cualquier estado posterior. No se esconde ningún grupo. De cada uno trae su
 * lugar y su desglose, nunca el de los demás: el ranking se lee solo con el id de ese docente, y de los demás solo se
 * cuenta cuántos hay. Un número constante de consultas, sin importar los grupos.
 */
final class TeacherOfferQuery
{
    /**
     * @return array{departments: list<array{id: string, code: string, name: string}>, groups: list<array<string, mixed>>}
     */
    public function forTeacher(Teacher $teacher, Term $term): array
    {
        $departments = Department::query()
            ->whereIn('id', $teacher->adscriptions()->select('department_id'))
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn (Department $department): array => ['id' => $department->id, 'code' => $department->code, 'name' => $department->name])
            ->all();

        if ($departments === []) {
            return ['departments' => [], 'groups' => []];
        }

        $groups = Group::query()
            ->active()
            ->where('groups.term_id', $term->id)
            ->whereIn('groups.department_id', array_column($departments, 'id'))
            ->whereExists(fn (QueryBuilder $load) => $load->select(DB::raw(1))->from('academic_loads')
                ->whereColumn('academic_loads.term_id', 'groups.term_id')
                ->whereColumn('academic_loads.campus_id', 'groups.campus_id')
                ->whereColumn('academic_loads.department_id', 'groups.department_id')
                ->whereIn('academic_loads.status', self::visibleStatuses()))
            ->with(['subject:id,code,name', 'ownerCareer:id,code', 'campus:id,code,name', 'teacher:id,name', 'sessions' => fn ($sessions) => $sessions->with('room:id,code')])
            ->orderBy('groups.semester')
            ->orderBy('groups.code')
            ->get();

        $ids = $groups->pluck('id')->all();

        $mine = StaffingRanking::query()->whereIn('group_id', $ids)->where('teacher_id', $teacher->id)->get()->keyBy('group_id');
        $totals = StaffingRanking::query()->whereIn('group_id', $ids)->groupBy('group_id')
            ->selectRaw('group_id, count(*) as total')->pluck('total', 'group_id');
        $weights = RankingWeights::fromSettings();

        return [
            'departments' => $departments,
            'groups' => $groups->map(fn (Group $group): array => [
                'id' => $group->id,
                'department_id' => $group->department_id,
                'code' => $group->code,
                'semester' => $group->semester,
                'subject_code' => $group->subject->code,
                'subject_name' => $group->subject->name,
                'career' => $group->ownerCareer->code,
                'campus_code' => $group->campus->code,
                'campus_name' => $group->campus->name,
                'schedule' => SessionLabels::schedule($group->sessions),
                'rooms' => $group->sessions->map(fn ($session): ?string => $session->room?->code)->filter()->unique()->values()->all(),
                'teacher_name' => $group->teacher?->name,
                'is_mine' => $group->teacher_id === $teacher->id,
                'rank' => ($ranking = $mine->get($group->id))?->rank,
                'total' => (int) ($totals[$group->id] ?? 0),
                'flags' => $ranking?->breakdown['flags'] ?? [],
                'breakdown' => $ranking === null ? null : self::breakdown($ranking, $weights),
            ])->all(),
        ];
    }

    /**
     * HC-01 visto por el docente, para el panel de un grupo con la marca «choque»: sus grupos no cancelados del periodo,
     * de cualquier campus, que tienen una sesión que se empalma con una del grupo (el mismo día y horas que se cruzan),
     * con solo esas sesiones. El ranking guarda la marca y no con qué choca; esto se calcula al abrir el panel con sus
     * sesiones de ahora, así que puede venir vacío si cambiaron después del ranking. Dos consultas.
     *
     * @return list<array{code: string, subject_name: string, campus_code: string, schedule: list<array{days: string, time: string}>}>
     */
    public function clashesWith(Teacher $teacher, Term $term, string $groupId): array
    {
        $sessions = GroupSession::query()->where('group_id', $groupId)->get(['day', 'starts_at', 'ends_at']);

        if ($sessions->isEmpty()) {
            return [];
        }

        $overlaps = fn (GroupSession $own): bool => $sessions->contains(fn (GroupSession $session): bool => $session->day === $own->day
            && Minutes::of($session->starts_at) < Minutes::of($own->ends_at)
            && Minutes::of($own->starts_at) < Minutes::of($session->ends_at));

        return Group::query()
            ->active()
            ->where('term_id', $term->id)
            ->where('teacher_id', $teacher->id)
            ->whereKeyNot($groupId)
            ->with(['subject:id,name', 'campus:id,code', 'sessions'])
            ->orderBy('code')
            ->get()
            ->map(fn (Group $group): array => [
                'code' => $group->code,
                'subject_name' => $group->subject->name,
                'campus_code' => $group->campus->code,
                'schedule' => SessionLabels::schedule($group->sessions->filter($overlaps)->values()),
            ])
            ->filter(fn (array $clash): bool => $clash['schedule'] !== [])
            ->values()
            ->all();
    }

    /**
     * Los estados de la carga desde los que el departamento ya ve la oferta: asignación y todo lo posterior.
     *
     * @return list<string>
     */
    public static function visibleStatuses(): array
    {
        return array_map(
            fn (LoadStatus $status): string => $status->value,
            array_filter(LoadStatus::cases(), fn (LoadStatus $status): bool => ! in_array($status, [LoadStatus::Draft, LoadStatus::Proposed], true)),
        );
    }

    /**
     * El desglose propio: cada criterio con lo que vale en el puntaje y las horas que le quedarían.
     *
     * @return array<string, mixed>
     */
    private static function breakdown(StaffingRanking $ranking, RankingWeights $weights): array
    {
        $data = $ranking->breakdown;
        $level = isset($data['qualification']) ? QualificationLevel::tryFrom($data['qualification']) : null;
        $evaluation = $data['evaluation'] ?? null;
        $history = (int) ($data['history_count'] ?? 0);

        return [
            'base_hours' => (int) ($data['base_hours'] ?? 0),
            'history_count' => $history,
            'history_points' => $history * $weights->perGroupTaught,
            'qualification' => $level?->label(),
            'qualification_points' => match ($level) {
                QualificationLevel::Preferente => $weights->preferente,
                QualificationLevel::Apto => $weights->apto,
                null => 0.0,
            },
            'evaluation' => $evaluation === null ? null : (float) $evaluation,
            'evaluation_points' => ($evaluation ?? 0.0) * $weights->evaluation,
            'score' => (float) $ranking->score,
            'remaining_hours' => $data['remaining_hours'] ?? null,
            'remaining_after_hours' => $data['remaining_after_hours'] ?? null,
        ];
    }
}
