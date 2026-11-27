<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Staffing\Enums\SelectionEntryStatus;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\SelectionEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El turno del propio docente en las selecciones de carga vivas de un periodo, para su Resumen (tarea 1.85, IN-03): una
 * entrada por selección viva (publicada, en curso o en ronda extra, como 1.33) donde tiene renglón, con su lugar entre
 * todos los renglones con turno de la orden, su horario y si le toca ahora (la regla de SelectionTurnQuery::isTurnOf, calculada aquí
 * con los renglones ya cargados para no hacer una consulta por selección). Solo lee el renglón del docente que se pasa: el del Resumen es el ligado al usuario, nunca un id del
 * navegador. Un renglón de elección previa (SEL-16, sin turno) o que ya cerró (`done`, `unresolved`) no da entrada: no
 * le queda nada que tomar. En la ronda extra el horario es el de su hora extra. Dos consultas, sin importar cuántas
 * selecciones sean.
 */
final class MySelectionTurnQuery
{
    /**
     * Ordenadas por inicio de su turno; `position` es el lugar guardado en la orden y `total` los renglones con turno
     * de esa selección (sin los de elección previa).
     *
     * @return list<array{selection_id: string, department: string, position: int, total: int, starts_at: ?CarbonImmutable, ends_at: ?CarbonImmutable, in_turn: bool}>
     */
    public function forTeacher(Teacher $teacher, Term $term, CarbonImmutable $now): array
    {
        $entries = SelectionEntry::query()
            ->where('teacher_id', $teacher->getKey())
            ->whereNull('prior_choice_at')
            ->whereHas('selection', fn (Builder $selection) => $selection
                ->where('term_id', $term->getKey())
                ->whereIn('status', array_map(fn (SelectionStatus $status): string => $status->value, SelectionStatus::live())))
            ->with(['selection' => fn (BelongsTo $selection) => $selection->with(['department' => fn (BelongsTo $department) => $department->withTrashed()->select(['id', 'name'])])])
            ->get()
            ->filter(fn (SelectionEntry $entry): bool => $entry->status === ($entry->selection->status === SelectionStatus::ExtraRound ? SelectionEntryStatus::PendingExtra : SelectionEntryStatus::Waiting))
            ->values();

        if ($entries->isEmpty()) {
            return [];
        }

        $totals = SelectionEntry::query()
            ->whereIn('selection_id', $entries->pluck('selection_id')->all())
            ->whereNull('prior_choice_at')
            ->selectRaw('selection_id, count(*) as total')
            ->groupBy('selection_id')
            ->pluck('total', 'selection_id');

        return $entries
            ->map(function (SelectionEntry $entry) use ($totals, $now): array {
                $extra = $entry->selection->status === SelectionStatus::ExtraRound;

                $startsAt = $extra ? $entry->extra_slot_starts_at : $entry->slot_starts_at;
                $endsAt = $extra ? $entry->extra_slot_ends_at : $entry->slot_ends_at;

                return [
                    'selection_id' => $entry->selection_id,
                    'department' => (string) $entry->selection->department?->name,
                    'position' => $entry->position,
                    'total' => (int) ($totals[$entry->selection_id] ?? 0),
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'in_turn' => $startsAt !== null && $endsAt !== null && $startsAt <= $now && $now < $endsAt,
                ];
            })
            ->sortBy(fn (array $turn): string => ($turn['starts_at']?->toIso8601String() ?? '9999').$turn['selection_id'])
            ->values()
            ->all();
    }
}
