<?php

use App\Modules\Core\Actions\CopyTermCalendar;
use App\Modules\Core\Actions\SetTermStage;
use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Events\TermCalendarChanged;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermStage;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| CopyTermCalendar: el calendario de un periodo se copia a otro corrido por semanas completas, reemplaza el que
| tenía y se escribe todo junto o nada.
*/

beforeEach(function () {
    $this->source = Term::factory()->create(['code' => '20263', 'starts_on' => '2026-08-17', 'ends_on' => '2026-12-11']);
    $this->target = Term::factory()->create(['code' => '20271', 'starts_on' => '2027-01-25', 'ends_on' => '2027-06-11']);
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    app(SetTermStage::class)->handle($this->source, Stage::Proposal, null, '2026-08-31', '2026-09-11');
    app(SetTermStage::class)->handle($this->source, Stage::Rooms, $this->c1, '2026-10-05', '2026-10-16');
});

it('copia las ventanas conservando el día de la semana, también al cruzar de año', function () {
    $written = app(CopyTermCalendar::class)->handle($this->target, $this->source);

    $general = TermStage::where('term_id', $this->target->id)->whereNull('campus_id')->sole();
    $own = TermStage::where('term_id', $this->target->id)->where('campus_id', $this->c1->id)->sole();

    expect($written)->toBe(2)
        ->and($general->starts_on->toDateString())->toBe('2027-02-08')
        ->and($general->starts_on->dayOfWeek)->toBe(1)
        ->and($general->ends_on->toDateString())->toBe('2027-02-19')
        ->and($own->starts_on->toDateString())->toBe('2027-03-15')
        ->and($own->ends_on->dayOfWeek)->toBe(5);
});

it('corre por semanas completas entre semestres del mismo año', function () {
    $other = Term::factory()->create(['starts_on' => '2026-01-26', 'ends_on' => '2026-06-12']);

    app(CopyTermCalendar::class)->handle($other, $this->source);

    expect(TermStage::where('term_id', $other->id)->whereNull('campus_id')->sole()->starts_on->toDateString())->toBe('2026-02-09');
});

it('reemplaza las ventanas que ya tenía el destino', function () {
    app(SetTermStage::class)->handle($this->target, Stage::Closing, null, '2027-05-03', '2027-05-14');

    app(CopyTermCalendar::class)->handle($this->target, $this->source);

    expect(TermStage::where('term_id', $this->target->id)->where('stage', Stage::Closing)->exists())->toBeFalse()
        ->and(TermStage::where('term_id', $this->target->id)->count())->toBe(2)
        ->and(TermStage::where('term_id', $this->source->id)->count())->toBe(2);
});

it('no copia las ventanas de un campus inactivo y rechaza un origen que solo tiene de esas', function () {
    $this->c1->update(['is_active' => false]);
    $onlyInactive = Term::factory()->create();
    TermStage::factory()->for($onlyInactive)->create(['stage' => Stage::Rooms, 'campus_id' => $this->c1->id]);

    expect(fn () => app(CopyTermCalendar::class)->handle($this->target, $onlyInactive))->toThrow(ValidationException::class);

    expect(app(CopyTermCalendar::class)->handle($this->target, $this->source))->toBe(1)
        ->and(TermStage::where('term_id', $this->target->id)->whereNotNull('campus_id')->count())->toBe(0);
});

it('rechaza el mismo periodo y un origen sin ventanas', function () {
    expect(fn () => app(CopyTermCalendar::class)->handle($this->source, $this->source))->toThrow(ValidationException::class)
        ->and(fn () => app(CopyTermCalendar::class)->handle($this->source, Term::factory()->create()))->toThrow(ValidationException::class);

    expect(TermStage::where('term_id', $this->source->id)->count())->toBe(2);
});

it('es atómico: si una escritura falla, el destino conserva su calendario', function () {
    app(SetTermStage::class)->handle($this->target, Stage::Closing, null, '2027-05-03', '2027-05-14');
    $original = TermStage::where('term_id', $this->target->id)->sole()->only(['stage', 'starts_on']);
    $activity = Activity::count();

    TermStage::creating(function (TermStage $window): void {
        if ($window->campus_id !== null) {
            throw new RuntimeException('falla');
        }
    });

    expect(fn () => app(CopyTermCalendar::class)->handle($this->target, $this->source))->toThrow(RuntimeException::class);

    expect(TermStage::where('term_id', $this->target->id)->sole()->only(['stage', 'starts_on']))->toEqual($original)
        ->and(Activity::count())->toBe($activity);
});

it('deja una entrada de bitácora en el destino con los conteos', function () {
    app(SetTermStage::class)->handle($this->target, Stage::Closing, null, '2027-05-03', '2027-05-14');

    app(CopyTermCalendar::class)->handle($this->target, $this->source);

    $log = Activity::where('description', 'Copió el calendario del periodo 20263')->sole();

    expect($log->subject->is($this->target))->toBeTrue()
        ->and($log->properties->only(['written', 'removed', 'skipped', 'source'])->all())->toBe(['source' => '20263', 'written' => 2, 'removed' => 1, 'skipped' => 0]);
});

it('avisa del cambio hasta después del commit y solo si se confirmó', function () {
    Event::fake([TermCalendarChanged::class]);

    app(CopyTermCalendar::class)->handle($this->target, $this->source);

    Event::assertDispatched(TermCalendarChanged::class, fn ($event) => $event->term->is($this->target));

    Event::fake([TermCalendarChanged::class]);
    try {
        app(CopyTermCalendar::class)->handle($this->target, Term::factory()->create());
    } catch (ValidationException) {
    }
    Event::assertNotDispatched(TermCalendarChanged::class);
});
