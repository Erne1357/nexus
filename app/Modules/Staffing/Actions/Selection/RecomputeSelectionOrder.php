<?php

namespace App\Modules\Staffing\Actions\Selection;

use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Rules\SelectionOrder;
use App\Modules\Staffing\Rules\SelectionSlots;
use Carbon\CarbonImmutable;

/**
 * Recalcula `position` y `slot_index` de la cola de una selección (SEL-07, SEL-10) y, si ya tiene fecha de inicio, la
 * hora de cada turno (SEL-08) y el fin de la primera ronda. Lo llaman las Actions que cambian la cola o la ventana,
 * dentro de su transacción; no deja bitácora propia.
 */
final class RecomputeSelectionOrder
{
    public function handle(Selection $selection): void
    {
        $entries = $selection->entries()->with('teacher:id,name,hired_on')->get();

        $ranked = collect(SelectionOrder::rank($entries->map(fn ($entry): array => [
            'key' => (string) $entry->getKey(),
            'source' => $entry->source->value,
            'tier' => $entry->tier,
            'seniority' => $entry->seniority_years === null ? null : (float) $entry->seniority_years,
            'hired_on' => ($entry->hired_on ?? $entry->teacher?->hired_on)?->toDateString(),
            'name' => (string) ($entry->teacher?->name ?? $entry->csv_name ?? ''),
        ])->all()))->keyBy('key');

        $slots = $selection->starts_at === null || $ranked->isEmpty()
            ? []
            : SelectionSlots::layout(
                CarbonImmutable::instance($selection->starts_at),
                substr((string) $selection->window_starts, 0, 5),
                substr((string) $selection->window_ends, 0, 5),
                array_map('intval', $selection->weekdays),
                (int) $selection->slot_minutes,
                $ranked->max('slot_index') + 1,
            );

        foreach ($entries as $entry) {
            $place = $ranked[(string) $entry->getKey()];
            $slot = $slots[$place['slot_index']] ?? null;
            $entry->update([
                'position' => $place['position'],
                'slot_index' => $place['slot_index'],
                'slot_starts_at' => $slot['starts_at'] ?? null,
                'slot_ends_at' => $slot['ends_at'] ?? null,
            ]);
        }

        $selection->update(['first_round_ends_at' => $slots === [] ? null : end($slots)['ends_at']]);
    }
}
