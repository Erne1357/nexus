<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Staffing\Enums\SelectionEntrySource;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Models\SelectionEntry;
use Carbon\CarbonImmutable;

/**
 * El orden de selección con su desglose (tarea 1.33, diseño 6.4): todos los de la cola lo ven completo, también los
 * criterios de sus compañeros (decisión 16). Por lugar: el turno (y el de la ronda extra), el escalón de la plaza y el
 * escalón final (SEL-06: «TC → ¾» con un criterio fallido), los cuatro criterios del CSV, la antigüedad, el estado del
 * renglón, si comparte la media hora con otro (SEL-07) y si está en turno ahora. Quien no viene en el CSV (SEL-10) va
 * sin escalón ni criterios y con su fecha de ingreso. Quien tiene elección previa (SEL-16) va aparte, primero, sin lugar ni turno y con sus horas (`prior`, `hours`); no
 * comparte turno con nadie. Tres consultas.
 *
 * @phpstan-type OrderRow array{position: int, slot_index: int, name: string, slot: ?string, extra_slot: ?string, plaza_tier_label: ?string, tier_label: ?string, criteria: ?array{auto_eval: ?bool, prior_compliance: ?bool, partials_1: ?bool, partials_2: ?bool}, seniority: ?string, status: string, source: string, teacher_id: ?string, base_hours: int, failed_criteria: int, tier: ?int, hired_on: ?string, tie: bool, in_turn: bool, prior: bool, hours: ?int}
 */
final class SelectionOrderQuery
{
    /** El nombre de cada escalón de plaza (SEL-06). */
    private const array PLAZAS = [0 => 'TC', 1 => '¾', 2 => '½', 3 => 'Asignatura'];

    public function __construct(private readonly SelectionTurnQuery $turns, private readonly SelectionGroupsQuery $groups) {}

    /**
     * @return list<OrderRow>
     */
    public function forSelection(Selection $selection, ?CarbonImmutable $now = null): array
    {
        $entries = SelectionEntry::query()
            ->where('selection_id', $selection->getKey())
            ->with('teacher:id,name')
            ->orderBy('position')
            ->orderBy('id')
            ->get();
        $inTurn = $this->turns->entriesInTurn($selection, $now ?? CarbonImmutable::now())->keyBy('id')->all();
        $perSlot = $entries->reject(fn (SelectionEntry $entry): bool => $entry->isPriorChoice())->countBy('slot_index');
        $priorIds = $entries->filter(fn (SelectionEntry $entry): bool => $entry->isPriorChoice())->pluck('teacher_id')->filter()->all();
        $hours = $priorIds === [] ? collect() : $this->groups->of($selection)
            ->whereIn('groups.teacher_id', $priorIds)
            ->join('subjects', 'subjects.id', '=', 'groups.subject_id')
            ->groupBy('groups.teacher_id')
            ->selectRaw('groups.teacher_id, sum(subjects.theory_hours + subjects.practice_hours) as hours')
            ->toBase()
            ->pluck('hours', 'teacher_id');

        return $entries->map(function (SelectionEntry $entry) use ($inTurn, $perSlot, $hours): array {
            $prior = $entry->isPriorChoice();
            $fromCsv = $entry->source === SelectionEntrySource::Csv;

            return [
                'position' => $entry->position,
                'slot_index' => $entry->slot_index,
                'name' => $entry->teacher?->name ?? (string) $entry->csv_name,
                'slot' => $entry->slot_starts_at?->toIso8601String(),
                'extra_slot' => $entry->extra_slot_starts_at?->toIso8601String(),
                'plaza_tier_label' => $fromCsv ? self::tierLabel($entry->plaza_tier) : null,
                'tier_label' => $fromCsv ? self::tierLabel($entry->tier) : null,
                'criteria' => $fromCsv ? [
                    'auto_eval' => $entry->auto_eval,
                    'prior_compliance' => $entry->prior_compliance,
                    'partials_1' => $entry->partials_1,
                    'partials_2' => $entry->partials_2,
                ] : null,
                'seniority' => $fromCsv && $entry->seniority_years !== null ? self::years($entry->seniority_years) : null,
                'status' => $prior ? 'prior_choice' : $entry->status->value,
                'source' => $entry->source->value,
                'teacher_id' => $entry->teacher_id,
                'base_hours' => $entry->base_hours,
                'failed_criteria' => $fromCsv ? $entry->failed_criteria : 0,
                'tier' => $fromCsv ? $entry->tier : null,
                'hired_on' => $fromCsv ? null : $entry->hired_on?->toDateString(),
                'tie' => ! $prior && ($perSlot[$entry->slot_index] ?? 0) > 1,
                'in_turn' => isset($inTurn[$entry->getKey()]),
                'prior' => $prior,
                'hours' => $prior ? (int) ($entry->closed_hours ?? $hours[$entry->teacher_id] ?? 0) : null,
            ];
        })->values()->all();
    }

    /**
     * El nombre de un escalón: TC, ¾, ½, Asignatura y, debajo, «Asignatura −1», «Asignatura −2»… (SEL-06, sin piso).
     */
    public static function tierLabel(int $tier): string
    {
        return self::PLAZAS[$tier] ?? 'Asignatura −'.($tier - 3);
    }

    /**
     * La antigüedad sin ceros de sobra: «28.5», «21».
     */
    private static function years(string $years): string
    {
        return str_contains($years, '.') ? rtrim(rtrim($years, '0'), '.') : $years;
    }
}
