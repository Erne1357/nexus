<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Staffing\Enums\SelectionEntryStatus;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Models\SelectionEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Estado en vivo de una selección publicada para el monitor del admin (diseño 6.8): turno vigente, avance por docente
 * (horas y grupos que lleva), ronda extra y renglones sin resolver. Una consulta por cada cosa, sin importar la cola.
 *
 * @phpstan-type Row array{entry: SelectionEntry, name: string, hours: int, groups: list<string>, current: bool}
 */
final class SelectionMonitorQuery
{
    public function __construct(private readonly SelectionTurnQuery $turns, private readonly SelectionGroupsQuery $groups) {}

    /**
     * @return array{current: ?SelectionEntry, current_name: ?string, rows: list<Row>, done: int, total: int, groups_taken: int, groups_total: int, hours_taken: int, hours_total: int, waiting: int, extra: list<Row>, unresolved: list<Row>}
     */
    public function for(Selection $selection, CarbonImmutable $now): array
    {
        $entries = $selection->entries()->with('teacher:id,name')->orderBy('position')->orderBy('id')->get();
        $current = $this->turns->currentEntry($selection, $now);

        $groups = $this->groups->of($selection)
            ->join('subjects', 'subjects.id', '=', 'groups.subject_id')
            ->get(['groups.id', 'groups.code', 'groups.teacher_id', DB::raw('subjects.theory_hours + subjects.practice_hours as hours')]);

        $byTeacher = $groups->whereNotNull('teacher_id')->groupBy('teacher_id');

        $rows = $entries->map(function (SelectionEntry $entry) use ($byTeacher, $current): array {
            $taken = $byTeacher->get($entry->teacher_id, collect());

            return [
                'entry' => $entry,
                'name' => $entry->teacher?->name ?? $entry->csv_name,
                'hours' => (int) $taken->sum('hours'),
                'groups' => $taken->pluck('code')->sort()->values()->all(),
                'current' => $current?->getKey() === $entry->getKey(),
            ];
        })->values();

        $taken = $groups->whereNotNull('teacher_id');

        return [
            'current' => $current,
            'current_name' => $rows->firstWhere('current', true)['name'] ?? null,
            'rows' => $rows->all(),
            'done' => $entries->where('status', SelectionEntryStatus::Done)->count(),
            'total' => $entries->count(),
            'groups_taken' => $taken->count(),
            'groups_total' => $groups->count(),
            'hours_taken' => (int) $taken->sum('hours'),
            'hours_total' => (int) $groups->sum('hours'),
            'waiting' => $entries->where('status', SelectionEntryStatus::Waiting)->count(),
            'extra' => $rows->filter(fn (array $row): bool => $row['entry']->status === SelectionEntryStatus::PendingExtra)->values()->all(),
            'unresolved' => $rows->filter(fn (array $row): bool => $row['entry']->status === SelectionEntryStatus::Unresolved)->values()->all(),
        ];
    }
}
