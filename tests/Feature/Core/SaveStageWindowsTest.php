<?php

use App\Modules\Core\Actions\SaveStageWindows;
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
| SaveStageWindows: las ventanas de una etapa (la general y las de campus) se guardan juntas o no se guardan.
*/

beforeEach(function () {
    $this->term = Term::factory()->create();
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
});

it('pone la general y las de campus, y quita las que vienen en null', function () {
    app(SetTermStage::class)->handle($this->term, Stage::Staffing, $this->c1, '2026-11-02', '2026-11-13');

    app(SaveStageWindows::class)->handle($this->term, Stage::Staffing, ['starts_on' => '2026-11-01', 'ends_on' => '2026-11-20'], [
        $this->c1->id => null,
        $this->c2->id => ['starts_on' => '2026-11-05', 'ends_on' => '2026-11-16'],
    ]);

    expect(TermStage::where('campus_id', $this->c1->id)->count())->toBe(0)
        ->and(TermStage::where('campus_id', $this->c2->id)->sole()->ends_on->toDateString())->toBe('2026-11-16')
        ->and(TermStage::whereNull('campus_id')->sole()->starts_on->toDateString())->toBe('2026-11-01');
});

it('quita la general con null y deja intactos los campus que no vienen', function () {
    app(SetTermStage::class)->handle($this->term, Stage::Staffing, null, '2026-11-02', '2026-11-13');
    app(SetTermStage::class)->handle($this->term, Stage::Staffing, $this->c1, '2026-11-02', '2026-11-13');

    app(SaveStageWindows::class)->handle($this->term, Stage::Staffing, null, []);

    expect(TermStage::count())->toBe(1)->and(TermStage::sole()->campus_id)->toBe($this->c1->id);
});

it('si una ventana de campus falla no guarda nada ni avisa', function (array $window, string $field) {
    $this->c2->update(['is_active' => false]);
    app(SetTermStage::class)->handle($this->term, Stage::Staffing, null, '2026-11-02', '2026-11-13');
    $activity = Activity::count();
    $heard = [];
    Event::listen(TermCalendarChanged::class, function () use (&$heard): void {
        $heard[] = true;
    });

    $save = fn () => app(SaveStageWindows::class)->handle($this->term, Stage::Staffing, ['starts_on' => '2026-12-01', 'ends_on' => '2026-12-10'], [
        $this->c1->id => ['starts_on' => '2026-12-02', 'ends_on' => '2026-12-09'],
        $this->c2->id => $window,
    ]);

    try {
        $save();
        $this->fail('Debía fallar.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey("own.{$this->c2->id}.{$field}");
    }

    expect(TermStage::count())->toBe(1)
        ->and(TermStage::sole()->ends_on->toDateString())->toBe('2026-11-13')
        ->and(Activity::count())->toBe($activity)
        ->and($heard)->toBe([]);
})->with([
    'campus inactivo' => [['starts_on' => '2026-12-02', 'ends_on' => '2026-12-09'], 'starts_on'],
    'fin antes del inicio' => [['starts_on' => '2026-12-09', 'ends_on' => '2026-12-02'], 'ends_on'],
    'fecha vacía' => [['starts_on' => '', 'ends_on' => '2026-12-02'], 'starts_on'],
]);

it('no avisa de nada cuando el guardado se revierte', function () {
    $this->c2->update(['is_active' => false]);
    $heard = [];
    Event::listen(TermCalendarChanged::class, function () use (&$heard): void {
        $heard[] = true;
    });

    expect(fn () => app(SaveStageWindows::class)->handle($this->term, Stage::Staffing, ['starts_on' => '2026-12-01', 'ends_on' => '2026-12-10'], [
        $this->c2->id => ['starts_on' => '2026-12-02', 'ends_on' => '2026-12-09'],
    ]))->toThrow(ValidationException::class);

    expect($heard)->toBe([])->and(TermStage::count())->toBe(0);
});

it('avisa una vez por ventana cambiada, después del commit', function () {
    $heard = [];
    Event::listen(TermCalendarChanged::class, function (TermCalendarChanged $event) use (&$heard): void {
        $heard[] = $event->campus?->code;
    });

    app(SaveStageWindows::class)->handle($this->term, Stage::Staffing, ['starts_on' => '2026-12-01', 'ends_on' => '2026-12-10'], [
        $this->c1->id => ['starts_on' => '2026-12-02', 'ends_on' => '2026-12-09'],
    ]);

    expect($heard)->toBe([null, 'C1']);
});
