<?php

namespace App\Modules\Staffing\Actions\Selection;

use App\Modules\Faculty\Models\Teacher;
use App\Modules\Staffing\Enums\SelectionEntrySource;
use App\Modules\Staffing\Enums\SelectionEntryStatus;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\PriorChoice;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Rules\SelectionOrder;
use App\Modules\Staffing\Rules\SelectionSlots;
use App\Modules\Staffing\Rules\TeacherNameMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Recalcula `position` y `slot_index` de la cola de una selección (SEL-07, SEL-10) y, si ya tiene fecha de inicio, la
 * hora de cada turno (SEL-08) y el fin de la primera ronda. SEL-16: quien tiene elección previa en el periodo y la
 * modalidad de la selección queda `done` con `prior_choice_at`, sin lugar ni turno, y no entra al orden ni a los turnos
 * (conserva su renglón del CSV, con sus criterios, si lo trae). Lo llaman las Actions que cambian la cola o la ventana,
 * dentro de su transacción; no deja bitácora propia.
 */
final class RecomputeSelectionOrder
{
    /**
     * Los docentes que pueden estar en la cola: activos y adscritos al departamento de la selección.
     *
     * @return Builder<Teacher>
     */
    public static function pool(Selection $selection): Builder
    {
        return Teacher::query()
            ->where('is_active', true)
            ->whereHas('adscriptions', fn ($query) => $query->where('department_id', $selection->department_id));
    }

    /**
     * Agrega al final a cada docente del grupo sin renglón que no sea candidato de un renglón del CSV todavía sin
     * resolver (SEL-10): si el admin descarta los renglones dudosos, el docente entra como agregado.
     */
    private function appendMissing(Selection $selection): void
    {
        $present = $selection->entries()->whereNotNull('teacher_id')->pluck('teacher_id')->all();
        $pool = self::pool($selection)->get(['id', 'name', 'hired_on']);
        $contested = [];

        foreach ($selection->entries()->whereNull('teacher_id')->whereNotNull('csv_name')->pluck('csv_name') as $name) {
            array_push($contested, ...TeacherNameMatcher::match($name, $pool)['candidates']);
        }

        foreach ($pool->reject(fn (Teacher $teacher): bool => in_array($teacher->getKey(), [...$present, ...$contested], true)) as $teacher) {
            $selection->entries()->create([
                'teacher_id' => $teacher->getKey(),
                'source' => SelectionEntrySource::Appended,
                'hired_on' => $teacher->hired_on,
                'status' => SelectionEntryStatus::Waiting,
            ]);
        }
    }

    /**
     * Los docentes con elección previa que aplica a esta selección (SEL-16): la marca es del periodo y la modalidad y
     * sus departamentos (los de sus grupos) incluyen alguno de las cargas de la selección, también como carga de apoyo.
     * Un docente cuyos grupos previos son de un solo departamento conserva su turno en la selección de otro.
     *
     * @return list<string>
     */
    private function priorTeachers(Selection $selection): array
    {
        $departments = $selection->loads()->pluck('academic_loads.department_id')->all();

        return PriorChoice::query()
            ->where('term_id', $selection->term_id)
            ->where('modality_id', $selection->modality_id)
            ->get(['teacher_id', 'department_ids'])
            ->filter(fn (PriorChoice $mark): bool => array_intersect($mark->department_ids ?? [], $departments) !== [])
            ->pluck('teacher_id')
            ->values()
            ->all();
    }

    public function handle(Selection $selection): void
    {
        $this->appendMissing($selection);

        $prior = $this->priorTeachers($selection);

        // Solo en borrador: una marca que ya no aplica (se quitó, RemovePriorChoice) devuelve al docente a la fila.
        if ($selection->status === SelectionStatus::Draft) {
            $selection->entries()->whereNotNull('prior_choice_at')->whereNotIn('teacher_id', $prior)->update([
                'prior_choice_at' => null,
                'status' => SelectionEntryStatus::Waiting,
            ]);
        }

        $selection->entries()->whereIn('teacher_id', $prior)->whereNull('prior_choice_at')->update(['prior_choice_at' => now()]);
        $selection->entries()->whereIn('teacher_id', $prior)->update([
            'status' => SelectionEntryStatus::Done,
            'position' => 0,
            'slot_index' => 0,
            'slot_starts_at' => null,
            'slot_ends_at' => null,
        ]);

        $entries = $selection->entries()->with('teacher:id,name,hired_on')
            ->whereNull('prior_choice_at')
            ->get();

        $ranked = collect(SelectionOrder::rank($entries->map(fn ($entry): array => [
            'key' => (string) $entry->getKey(),
            'source' => $entry->source->value,
            'tier' => $entry->tier,
            'failed' => (int) $entry->failed_criteria,
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
