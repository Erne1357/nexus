<?php

use App\Modules\Core\Actions\SaveStageWindows;
use App\Modules\Core\Actions\SetTermStage;
use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Events\TermCalendarChanged;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermStage;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| SaveStageWindows: las ventanas de una etapa (la general y las de modalidad, M4) se guardan juntas o no se guardan.
*/

beforeEach(function () {
    $this->term = Term::factory()->create();
    $this->m1 = Modality::factory()->create(['code' => 'M001']);
    $this->m2 = Modality::factory()->create(['code' => 'M002']);
});

it('pone la general y las de modalidad, y quita las que vienen en null', function () {
    app(SetTermStage::class)->handle($this->term, Stage::Staffing, $this->m1, '2026-11-02', '2026-11-13');

    app(SaveStageWindows::class)->handle($this->term, Stage::Staffing, ['starts_on' => '2026-11-01', 'ends_on' => '2026-11-20'], [
        $this->m1->id => null,
        $this->m2->id => ['starts_on' => '2026-11-05', 'ends_on' => '2026-11-16'],
    ]);

    expect(TermStage::where('modality_id', $this->m1->id)->count())->toBe(0)
        ->and(TermStage::where('modality_id', $this->m2->id)->sole()->ends_on->toDateString())->toBe('2026-11-16')
        ->and(TermStage::whereNull('modality_id')->sole()->starts_on->toDateString())->toBe('2026-11-01');
});

it('quita la general con null y deja intactas las modalidades que no vienen', function () {
    app(SetTermStage::class)->handle($this->term, Stage::Staffing, null, '2026-11-02', '2026-11-13');
    app(SetTermStage::class)->handle($this->term, Stage::Staffing, $this->m1, '2026-11-02', '2026-11-13');

    app(SaveStageWindows::class)->handle($this->term, Stage::Staffing, null, []);

    expect(TermStage::count())->toBe(1)->and(TermStage::sole()->modality_id)->toBe($this->m1->id);
});

it('si una ventana de modalidad falla no guarda nada ni avisa', function (array $window, string $field) {
    $this->m2->update(['is_active' => false]);
    app(SetTermStage::class)->handle($this->term, Stage::Staffing, null, '2026-11-02', '2026-11-13');
    $activity = Activity::count();
    $heard = [];
    Event::listen(TermCalendarChanged::class, function () use (&$heard): void {
        $heard[] = true;
    });

    $save = fn () => app(SaveStageWindows::class)->handle($this->term, Stage::Staffing, ['starts_on' => '2026-12-01', 'ends_on' => '2026-12-10'], [
        $this->m1->id => ['starts_on' => '2026-12-02', 'ends_on' => '2026-12-09'],
        $this->m2->id => $window,
    ]);

    try {
        $save();
        $this->fail('Debía fallar.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey("own.{$this->m2->id}.{$field}");
    }

    expect(TermStage::count())->toBe(1)
        ->and(TermStage::sole()->ends_on->toDateString())->toBe('2026-11-13')
        ->and(Activity::count())->toBe($activity)
        ->and($heard)->toBe([]);
})->with([
    'modalidad inactiva' => [['starts_on' => '2026-12-02', 'ends_on' => '2026-12-09'], 'starts_on'],
    'fin antes del inicio' => [['starts_on' => '2026-12-09', 'ends_on' => '2026-12-02'], 'ends_on'],
    'fecha vacía' => [['starts_on' => '', 'ends_on' => '2026-12-02'], 'starts_on'],
]);

it('no avisa de nada cuando el guardado se revierte', function () {
    $this->m2->update(['is_active' => false]);
    $heard = [];
    Event::listen(TermCalendarChanged::class, function () use (&$heard): void {
        $heard[] = true;
    });

    expect(fn () => app(SaveStageWindows::class)->handle($this->term, Stage::Staffing, ['starts_on' => '2026-12-01', 'ends_on' => '2026-12-10'], [
        $this->m2->id => ['starts_on' => '2026-12-02', 'ends_on' => '2026-12-09'],
    ]))->toThrow(ValidationException::class);

    expect($heard)->toBe([])->and(TermStage::count())->toBe(0);
});

it('avisa una vez por ventana cambiada, después del commit', function () {
    $heard = [];
    Event::listen(TermCalendarChanged::class, function (TermCalendarChanged $event) use (&$heard): void {
        $heard[] = $event->modality?->code;
    });

    app(SaveStageWindows::class)->handle($this->term, Stage::Staffing, ['starts_on' => '2026-12-01', 'ends_on' => '2026-12-10'], [
        $this->m1->id => ['starts_on' => '2026-12-02', 'ends_on' => '2026-12-09'],
    ]);

    expect($heard)->toBe([null, 'M001']);
});
