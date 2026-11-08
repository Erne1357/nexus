<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Enums\AvailabilityKind;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherAvailability;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Models\GroupSession;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * El horario de un docente en un periodo (tarea 3.5, vista por docente del editor; la reusa «mi carga», 3.10): las
 * sesiones de sus grupos sin cancelar en todos los campus, porque un docente da clase en cualquiera, por día y hora, y
 * su disponibilidad del periodo (los bloques no disponibles y los preferidos de `teacher_availability`). No decide
 * quién lo ve: eso es de la pantalla.
 */
final class TeacherScheduleQuery
{
    /**
     * @return array{
     *     sessions: list<array{id: string, group_id: string, group_code: string, subject_code: string, subject_name: string, campus_id: string, campus_code: string, campus_name: string, day: int, starts_at: string, ends_at: string, kind: SessionKind, room: ?array{id: string, code: string}}>,
     *     availability: list<array{day: int, starts_at: string, ends_at: string, kind: AvailabilityKind}>,
     * }
     */
    public function for(Teacher $teacher, Term $term): array
    {
        $withTrashed = fn (Relation $query) => $query->withTrashed();

        $sessions = GroupSession::query()
            ->where('teacher_id', $teacher->id)
            ->where('term_id', $term->id)
            ->where('is_active', true)
            ->with(['group', 'group.subject' => $withTrashed, 'group.campus' => $withTrashed, 'room' => $withTrashed])
            ->orderBy('day')
            ->orderBy('starts_at')
            ->get()
            ->map(fn (GroupSession $session): array => [
                'id' => $session->id,
                'group_id' => $session->group_id,
                'group_code' => $session->group->code,
                'subject_code' => $session->group->subject->code,
                'subject_name' => $session->group->subject->name,
                'campus_id' => $session->group->campus_id,
                'campus_code' => $session->group->campus->code,
                'campus_name' => $session->group->campus->name,
                'day' => $session->day,
                'starts_at' => substr($session->starts_at, 0, 5),
                'ends_at' => substr($session->ends_at, 0, 5),
                'kind' => $session->kind,
                'room' => $session->room === null ? null : ['id' => $session->room->id, 'code' => $session->room->code],
            ])
            ->all();

        $availability = TeacherAvailability::query()
            ->where('teacher_id', $teacher->id)
            ->where('term_id', $term->id)
            ->orderBy('day')
            ->orderBy('starts_at')
            ->get(['day', 'starts_at', 'ends_at', 'kind'])
            ->map(fn (TeacherAvailability $block): array => [
                'day' => (int) $block->day,
                'starts_at' => substr($block->starts_at, 0, 5),
                'ends_at' => substr($block->ends_at, 0, 5),
                'kind' => $block->kind,
            ])
            ->all();

        return ['sessions' => $sessions, 'availability' => $availability];
    }
}
