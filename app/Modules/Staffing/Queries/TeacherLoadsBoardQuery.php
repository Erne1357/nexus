<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Staffing\Models\TeacherTermLoad;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lee el tablero de carga por docente (tarea 3.12) de un departamento y un periodo, en cinco consultas sin importar
 * cuántos docentes haya: los docentes activos con adscripción al departamento (en cualquier campus, porque no son de
 * un campus), sus filas de `teacher_term_loads`, sus plazas vigentes al inicio del periodo, sus grupos activos con
 * materia y campus, y las sesiones de esos grupos. Un docente sin fila todavía no se calculó: no entra a los
 * mosaicos y solo se cuenta en `pending`.
 */
final class TeacherLoadsBoardQuery
{
    public function forDepartment(Term $term, Department $department): TeacherLoadsBoard
    {
        $teachers = Teacher::query()
            ->where('is_active', true)
            ->whereHas('adscriptions', fn ($adscription) => $adscription->where('department_id', $department->getKey()))
            ->orderBy('name')
            ->get(['id', 'name', 'rfc']);

        $ids = $teachers->modelKeys();

        $loads = TeacherTermLoad::query()->where('term_id', $term->getKey())->whereIn('teacher_id', $ids)->get()->keyBy('teacher_id');

        $positions = TeacherPosition::query()->currentOn($term->starts_on)->whereIn('teacher_id', $ids)
            ->orderBy('kind')->orderBy('id')
            ->get(['teacher_id', 'kind', 'base_hours'])->groupBy('teacher_id');

        $groups = Group::query()
            ->join('subjects', 'subjects.id', '=', 'groups.subject_id')
            ->join('campuses', 'campuses.id', '=', 'groups.campus_id')
            ->where('groups.term_id', $term->getKey())
            ->whereIn('groups.teacher_id', $ids)
            ->where('groups.status', '<>', GroupStatus::Cancelled->value)
            ->orderBy('campuses.code')->orderBy('groups.code')
            ->toBase()
            ->get(['groups.id', 'groups.teacher_id', 'groups.code', 'campuses.code as campus', 'subjects.code as subject_code', 'subjects.name as subject', DB::raw('subjects.theory_hours + subjects.practice_hours as hours')]);

        $sessions = GroupSession::query()->whereIn('group_id', $groups->pluck('id')->all())
            ->orderBy('day')->orderBy('starts_at')
            ->get(['group_id', 'day', 'starts_at', 'ends_at'])->groupBy('group_id');

        $rows = [];

        foreach ($teachers as $teacher) {
            $load = $loads->get($teacher->id);

            if ($load === null) {
                continue;
            }

            $kinds = $positions->get($teacher->id, collect());
            $mine = $groups->where('teacher_id', $teacher->id);
            $byCampus = [];
            $weekly = [];

            foreach ($mine as $group) {
                $byCampus[$group->campus] = ($byCampus[$group->campus] ?? 0) + (int) $group->hours;

                foreach ($sessions->get($group->id, collect()) as $session) {
                    $weekly[] = ['day' => (int) $session->day, 'from' => Minutes::of($session->starts_at), 'to' => Minutes::of($session->ends_at),
                        'campus' => $group->campus, 'code' => $group->code, 'subject' => $group->subject];
                }
            }

            ksort($byCampus);

            $rows[] = new TeacherLoadRow(
                $teacher->id,
                $teacher->name,
                (string) $teacher->rfc,
                $load->base_hours,
                $load->external_hours,
                $load->assigned_hours,
                $load->limit_hours,
                $load->remaining_hours,
                $load->groups_count,
                $kinds->map(fn (TeacherPosition $position): string => $position->kind->value)->unique()->values()->all(),
                self::labelsOf($kinds),
                $kinds->contains(fn (TeacherPosition $position): bool => $position->kind === PositionKind::Honorarios),
                $byCampus,
                $mine->map(fn (object $group): array => ['code' => $group->code, 'subject' => $group->subject, 'subject_code' => $group->subject_code,
                    'campus' => $group->campus, 'hours' => (int) $group->hours])->values()->all(),
                $weekly,
            );
        }

        return new TeacherLoadsBoard($rows, $teachers->count() - count($rows));
    }

    /**
     * El nombre corto de cada tipo de plaza vigente: TC, 3/4, MT, «Asig. 12 h» (con sus horas), Hon. y Apoyo.
     *
     * @param  Collection<int, TeacherPosition>  $positions
     * @return list<string>
     */
    public static function labelsOf($positions): array
    {
        return $positions->groupBy(fn (TeacherPosition $position): string => $position->kind->value)
            ->map(fn ($group, string $kind): string => match (PositionKind::from($kind)) {
                PositionKind::TiempoCompleto => 'TC',
                PositionKind::TresCuartos => '3/4',
                PositionKind::MedioTiempo => 'MT',
                PositionKind::Asignatura => 'Asig. '.$group->sum('base_hours').' h',
                PositionKind::Honorarios => 'Hon.',
                PositionKind::Apoyo => 'Apoyo',
            })
            ->values()
            ->all();
    }
}
