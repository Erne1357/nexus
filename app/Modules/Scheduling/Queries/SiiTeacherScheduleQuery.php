<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\Academic\Rules\SubjectTeacherHours;
use Illuminate\Support\Facades\DB;

/**
 * Los grupos de un docente en un periodo según el horario histórico del SII (`historical_group_sessions` con
 * `historical_groups` de fuente `sii`), buscado por su RFC (tarea 1.26 / #755, «Mi carga» del semestre en curso).
 * Las sesiones guardan el aula y el RFC como texto: el aula se resuelve contra `rooms.code` del campus del grupo (si
 * no se encuentra, se conserva la clave del SII) y el docente es el que trae su RFC en la sesión. Un grupo con sesiones
 * de varios docentes trae solo las del docente. Solo lectura: el SII sigue siendo la fuente de ese periodo. Las horas
 * del grupo son las que le cuenta al docente (PL-02: 0 si la materia no cuenta en su carga, como las tutorías), con la
 * marca en `counts_teacher_hours`, igual que los grupos de Nexus.
 */
final class SiiTeacherScheduleQuery
{
    /**
     * @return list<array{id: string, code: string, subject_code: string, subject_name: string, campus_code: string, campus_name: string, department: string, hours: int, counts_teacher_hours: bool, sessions: list<array{day: int, starts_at: string, ends_at: string, room: ?string}>}>
     */
    public function forTeacher(?string $rfc, string $termCode): array
    {
        $rfc = $rfc === null ? '' : trim($rfc);

        if ($rfc === '') {
            return [];
        }

        $rows = DB::table('historical_group_sessions as s')
            ->join('historical_groups as g', 'g.id', '=', 's.historical_group_id')
            ->join('subjects', 'subjects.id', '=', 'g.subject_id')
            ->leftJoin('departments', 'departments.id', '=', 'subjects.department_id')
            ->leftJoin('campuses', 'campuses.id', '=', 'g.campus_id')
            ->where('g.term_code', $termCode)
            ->where('g.source', 'sii')
            ->whereRaw('upper(s.teacher_rfc) = ?', [mb_strtoupper($rfc)])
            ->orderBy('g.group_code')
            ->orderBy('subjects.code')
            ->orderBy('s.day')
            ->orderBy('s.starts_at')
            ->get([
                'g.id as group_id', 'g.group_code', 'g.campus_id', 'subjects.code as subject_code', 'subjects.name as subject_name',
                'subjects.theory_hours', 'subjects.practice_hours', 'subjects.counts_teacher_hours', 'departments.name as department',
                'campuses.code as campus_code', 'campuses.name as campus_name',
                's.day', 's.starts_at', 's.ends_at', 's.room_code',
            ]);

        $campusIds = $rows->pluck('campus_id')->filter()->unique()->values();
        $rooms = $campusIds->isEmpty() ? collect() : DB::table('rooms')->whereIn('campus_id', $campusIds)->whereNull('deleted_at')->get(['campus_id', 'code'])
            ->mapWithKeys(fn (object $room): array => [$room->campus_id.'|'.$room->code => $room->code]);

        return $rows->groupBy('group_id')->map(function ($sessions): array {
            $first = $sessions->first();

            return [
                'id' => $first->group_id,
                'code' => $first->group_code,
                'subject_code' => $first->subject_code,
                'subject_name' => $first->subject_name,
                'campus_code' => (string) $first->campus_code,
                'campus_name' => (string) $first->campus_name,
                'department' => (string) $first->department,
                'hours' => SubjectTeacherHours::of((int) $first->theory_hours, (int) $first->practice_hours, (bool) $first->counts_teacher_hours),
                'counts_teacher_hours' => (bool) $first->counts_teacher_hours,
                'sessions' => $sessions->map(fn (object $session): array => [
                    'day' => (int) $session->day,
                    'starts_at' => (string) $session->starts_at,
                    'ends_at' => (string) $session->ends_at,
                    'room' => $session->room_code,
                ])->all(),
            ];
        })->map(function (array $group) use ($rooms, $rows): array {
            $campusId = $rows->firstWhere('group_id', $group['id'])->campus_id;
            $group['sessions'] = array_map(function (array $session) use ($rooms, $campusId): array {
                $session['room'] = $session['room'] === null ? null : ($rooms[$campusId.'|'.$session['room']] ?? $session['room']);

                return $session;
            }, $group['sessions']);

            return $group;
        })->values()->all();
    }
}
