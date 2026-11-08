<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\Core\Models\Term;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Models\RoomBlock;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Models\GroupSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * La ocupación de un salón en un periodo (tarea 3.5, vista por salón del editor): las sesiones de los grupos sin
 * cancelar que lo usan, por día y hora, con su materia y docente, y sus bloqueos (`room_blocks`) del periodo o de todos
 * los periodos. No decide quién lo ve: eso es de la pantalla.
 */
final class RoomScheduleQuery
{
    /**
     * @return array{
     *     sessions: list<array{id: string, group_id: string, group_code: string, subject_code: string, subject_name: string, teacher: ?string, day: int, starts_at: string, ends_at: string, kind: SessionKind}>,
     *     blocks: list<array{day: int, starts_at: string, ends_at: string, reason: string, all_terms: bool}>,
     * }
     */
    public function for(Room $room, Term $term): array
    {
        $withTrashed = fn (Relation $query) => $query->withTrashed();

        $sessions = GroupSession::query()
            ->where('room_id', $room->id)
            ->where('term_id', $term->id)
            ->where('is_active', true)
            ->with(['group', 'group.subject' => $withTrashed, 'group.teacher' => $withTrashed])
            ->orderBy('day')
            ->orderBy('starts_at')
            ->get()
            ->map(fn (GroupSession $session): array => [
                'id' => $session->id,
                'group_id' => $session->group_id,
                'group_code' => $session->group->code,
                'subject_code' => $session->group->subject->code,
                'subject_name' => $session->group->subject->name,
                'teacher' => $session->group->teacher?->name,
                'day' => $session->day,
                'starts_at' => substr($session->starts_at, 0, 5),
                'ends_at' => substr($session->ends_at, 0, 5),
                'kind' => $session->kind,
            ])
            ->all();

        $blocks = RoomBlock::query()
            ->where('room_id', $room->id)
            ->where(fn (Builder $query) => $query->whereNull('term_id')->orWhere('term_id', $term->id))
            ->orderBy('day')
            ->orderBy('starts_at')
            ->get(['day', 'starts_at', 'ends_at', 'reason', 'term_id'])
            ->map(fn (RoomBlock $block): array => [
                'day' => (int) $block->day,
                'starts_at' => substr($block->starts_at, 0, 5),
                'ends_at' => substr($block->ends_at, 0, 5),
                'reason' => (string) $block->reason,
                'all_terms' => $block->term_id === null,
            ])
            ->all();

        return ['sessions' => $sessions, 'blocks' => $blocks];
    }
}
