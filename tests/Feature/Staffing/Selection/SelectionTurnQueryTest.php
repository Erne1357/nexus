<?php

use App\Modules\Staffing\Enums\SelectionEntryStatus;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Queries\SelectionTurnQuery;
use Carbon\CarbonImmutable;
use Tests\Support\Staffing\SelectionWorld;

/*
| SelectionTurnQuery (tarea 1.33, diseño 6.3): el turno vigente sale de las horas guardadas de cada renglón y de la hora
| actual, no del comando que avanza la selección. Un renglón terminado nunca tiene turno.
*/

beforeEach(function () {
    $this->now = CarbonImmutable::parse('2027-01-11 10:10', 'America/Ciudad_Juarez');
});

it('en la primera ronda el turno es el del renglón cuya hora cubre ahora', function (SelectionStatus $status) {
    $world = SelectionWorld::create($status);
    $current = $world->teacher(slotStartsAt: $this->now->subMinutes(10));
    $next = $world->teacher(slotStartsAt: $this->now->addMinutes(20), position: 2, name: 'Docente Dos');
    $turns = app(SelectionTurnQuery::class);

    expect($turns->currentEntry($world->selection, $this->now)?->teacher_id)->toBe($current->id)
        ->and($turns->isTurnOf($world->selection, $current, $this->now))->toBeTrue()
        ->and($turns->isTurnOf($world->selection, $next, $this->now))->toBeFalse()
        ->and($turns->isTurnOf($world->selection, $current, $this->now->addMinutes(20)))->toBeFalse()
        ->and($turns->isTurnOf($world->selection, $next, $this->now->addMinutes(20)))->toBeTrue();
})->with([
    'publicada, antes de que corra el comando' => [SelectionStatus::Published],
    'en curso' => [SelectionStatus::Running],
]);

it('en la ronda extra el turno sale de la hora extra', function () {
    $world = SelectionWorld::create(SelectionStatus::ExtraRound);
    $pending = $world->teacher(slotStartsAt: $this->now->subDay(), entry: [
        'status' => SelectionEntryStatus::PendingExtra,
        'extra_slot_starts_at' => $this->now->subMinutes(10),
        'extra_slot_ends_at' => $this->now->addMinutes(20),
    ]);
    $turns = app(SelectionTurnQuery::class);

    expect($turns->isTurnOf($world->selection, $pending, $this->now))->toBeTrue()
        ->and($turns->isTurnOf($world->selection, $pending, $this->now->subDay()->addMinutes(5)))->toBeFalse()
        ->and($turns->currentEntry($world->selection, $this->now)?->teacher_id)->toBe($pending->id);
});

it('un renglón terminado nunca tiene turno', function () {
    $world = SelectionWorld::create();
    $done = $world->teacher(slotStartsAt: $this->now->subMinutes(10), entry: ['status' => SelectionEntryStatus::Done]);
    $peer = $world->teacher(slotStartsAt: $this->now->subMinutes(10), position: 2, name: 'Docente Par');
    $turns = app(SelectionTurnQuery::class);

    expect($turns->isTurnOf($world->selection, $done, $this->now))->toBeFalse()
        ->and($turns->isTurnOf($world->selection, $peer, $this->now))->toBeTrue()
        ->and($turns->currentEntry($world->selection, $this->now)?->teacher_id)->toBe($peer->id);
});

it('quien cerró con menos horas en la primera ronda ya no tiene turno en ella', function () {
    $world = SelectionWorld::create();
    $closed = $world->teacher(slotStartsAt: $this->now->subMinutes(10), entry: ['status' => SelectionEntryStatus::PendingExtra]);

    expect(app(SelectionTurnQuery::class)->isTurnOf($world->selection, $closed, $this->now))->toBeFalse();
});

it('una selección en borrador, terminada o cancelada no tiene turno', function (SelectionStatus $status) {
    $world = SelectionWorld::create($status);
    $teacher = $world->teacher(slotStartsAt: $this->now->subMinutes(10));
    $turns = app(SelectionTurnQuery::class);

    expect($turns->isTurnOf($world->selection, $teacher, $this->now))->toBeFalse()
        ->and($turns->currentEntry($world->selection, $this->now))->toBeNull();
})->with([
    'borrador' => [SelectionStatus::Draft],
    'terminada' => [SelectionStatus::Finished],
    'cancelada' => [SelectionStatus::Cancelled],
]);
