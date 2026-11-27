<?php

use App\Modules\Faculty\Models\Teacher;
use App\Modules\Staffing\Enums\SelectionEntrySource;
use App\Modules\Staffing\Enums\SelectionEntryStatus;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Models\SelectionEntry;
use App\Modules\Staffing\Queries\SelectionOrderQuery;
use Carbon\CarbonImmutable;

/*
| SelectionOrderQuery (tarea 1.33, diseño 6.4 y decisión 16): el orden de selección con su desglose para todos los de la
| cola. Escalón de la plaza y escalón final (SEL-06), los cuatro criterios, la antigüedad, el turno, si comparte turno
| (SEL-07) y el estado; quien no viene en el CSV va sin criterios (SEL-10). Nombres ficticios.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-07 13:05', 'America/Ciudad_Juarez'));
    $this->selection = Selection::factory()->create(['status' => SelectionStatus::Running]);
    $this->start = CarbonImmutable::parse('2026-12-07 13:00', 'America/Ciudad_Juarez');
});

function orderEntry(array $attributes, string $name): SelectionEntry
{
    $slot = test()->start->addMinutes(30 * ($attributes['slot_index'] ?? 0));

    return SelectionEntry::factory()->for(test()->selection)->create([
        'teacher_id' => Teacher::factory()->create(['name' => $name])->id,
        'slot_starts_at' => $slot,
        'slot_ends_at' => $slot->addMinutes(30),
        ...$attributes,
    ]);
}

it('da el orden con escalones, criterios, antigüedad, turno y estado', function () {
    orderEntry(['position' => 2, 'slot_index' => 1, 'base_hours' => 40, 'plaza_tier' => 0, 'failed_criteria' => 1, 'tier' => 1, 'partials_2' => false, 'seniority_years' => 21], 'Docente Dos');
    orderEntry(['position' => 1, 'slot_index' => 0, 'base_hours' => 40, 'plaza_tier' => 0, 'tier' => 0, 'seniority_years' => 28.5, 'status' => SelectionEntryStatus::Done], 'Docente Uno');
    orderEntry(['position' => 3, 'slot_index' => 2, 'base_hours' => 12, 'plaza_tier' => 3, 'failed_criteria' => 2, 'tier' => 5, 'auto_eval' => false, 'prior_compliance' => false, 'seniority_years' => 3], 'Docente Tres');

    $rows = app(SelectionOrderQuery::class)->forSelection($this->selection);

    expect(array_column($rows, 'name'))->toBe(['Docente Uno', 'Docente Dos', 'Docente Tres'])
        ->and($rows[0])->toMatchArray([
            'position' => 1, 'slot_index' => 0, 'plaza_tier_label' => 'TC', 'tier_label' => 'TC', 'seniority' => '28.5',
            'status' => 'done', 'source' => 'csv', 'slot' => $this->start->toIso8601String(), 'extra_slot' => null,
            'criteria' => ['auto_eval' => true, 'prior_compliance' => true, 'partials_1' => true, 'partials_2' => true],
        ])
        ->and($rows[1])->toMatchArray(['plaza_tier_label' => 'TC', 'tier_label' => '¾', 'seniority' => '21', 'status' => 'waiting'])
        ->and($rows[1]['criteria']['partials_2'])->toBeFalse()
        ->and($rows[2])->toMatchArray(['plaza_tier_label' => 'Asignatura', 'tier_label' => 'Asignatura −2'])
        ->and($rows[2]['criteria'])->toBe(['auto_eval' => false, 'prior_compliance' => false, 'partials_1' => true, 'partials_2' => true]);
});

it('SEL-07: marca el turno compartido y quién está en turno ahora', function () {
    orderEntry(['position' => 1, 'slot_index' => 0], 'Docente Uno');
    orderEntry(['position' => 2, 'slot_index' => 0], 'Docente Par');
    orderEntry(['position' => 3, 'slot_index' => 1], 'Docente Tres');

    $rows = app(SelectionOrderQuery::class)->forSelection($this->selection);

    expect(array_column($rows, 'tie'))->toBe([true, true, false])
        ->and(array_column($rows, 'in_turn'))->toBe([true, true, false]);
});

it('SEL-10: quien no viene en el CSV va sin criterios ni escalón, con su fecha de ingreso', function () {
    orderEntry(['position' => 1, 'slot_index' => 0, 'source' => SelectionEntrySource::Appended, 'seniority_years' => null, 'hired_on' => '2019-08-01', 'auto_eval' => null, 'prior_compliance' => null, 'partials_1' => null, 'partials_2' => null], 'Docente Nuevo');

    $row = app(SelectionOrderQuery::class)->forSelection($this->selection)[0];

    expect($row)->toMatchArray(['source' => 'appended', 'criteria' => null, 'plaza_tier_label' => null, 'tier_label' => null, 'seniority' => null, 'hired_on' => '2019-08-01']);
});
