<?php

use App\Modules\Core\Actions\RemoveTermStage;
use App\Modules\Core\Actions\SetTermStage;
use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Enums\StageSource;
use App\Modules\Core\Enums\StageStatus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermStage;
use App\Modules\Core\Queries\StageWindow;
use App\Modules\Core\Queries\TermCalendarQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/*
| TermCalendarQuery: las 8 etapas con su ventana efectiva (la de la modalidad reemplaza a la general; M4, confirmado el
| 12 de noviembre de 2026), su estado respecto a hoy en Ciudad Juárez y su caché, invalidada al cambiar el calendario.
*/

beforeEach(function () {
    $this->term = Term::factory()->create();
    $this->modality = modalityOf('PRES');
    Cache::flush();
});

function termCalendarFor(Term $term, ?Modality $modality, Stage $stage): StageWindow
{
    return collect(app(TermCalendarQuery::class)->for($term, $modality))->firstWhere('stage', $stage);
}

it('devuelve las 8 etapas en el orden del enum, sin fecha si no hay ventana', function () {
    $windows = app(TermCalendarQuery::class)->for($this->term, $this->modality);

    expect(array_map(fn (StageWindow $window) => $window->stage, $windows))->toBe(Stage::cases())
        ->and(collect($windows)->every(fn (StageWindow $window) => $window->status === StageStatus::Unscheduled && $window->source === StageSource::None && $window->daysLeft === null))->toBeTrue();
});

it('M4: una ventana propia de PRES reemplaza a la general solo en PRES', function () {
    $other = Modality::factory()->create();
    TermStage::factory()->for($this->term)->create(['stage' => Stage::Proposal, 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-13']);
    TermStage::factory()->for($this->term)->create(['stage' => Stage::Proposal, 'modality_id' => $this->modality->id, 'starts_on' => '2026-11-05', 'ends_on' => '2026-11-20']);

    $own = termCalendarFor($this->term, $this->modality, Stage::Proposal);
    $fallback = termCalendarFor($this->term, $other, Stage::Proposal);
    $none = termCalendarFor($this->term, null, Stage::Proposal);

    expect($own->source)->toBe(StageSource::Modality)->and($own->startsOn->toDateString())->toBe('2026-11-05')
        ->and($fallback->source)->toBe(StageSource::General)->and($fallback->endsOn->toDateString())->toBe('2026-11-13')
        ->and($none->source)->toBe(StageSource::General)->and($none->startsOn->toDateString())->toBe('2026-11-02');
});

it('sin modalidad ignora las ventanas de modalidad', function () {
    TermStage::factory()->for($this->term)->create(['stage' => Stage::Rooms, 'modality_id' => $this->modality->id]);

    expect(termCalendarFor($this->term, null, Stage::Rooms)->source)->toBe(StageSource::None);
});

it('calcula el estado y los días restantes respecto a hoy en Ciudad Juárez', function () {
    TermStage::factory()->for($this->term)->create(['stage' => Stage::Proposal, 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-13']);

    $at = function (string $now): StageWindow {
        $this->travelTo(CarbonImmutable::parse($now, 'America/Ciudad_Juarez'));

        return termCalendarFor($this->term, null, Stage::Proposal);
    };

    $before = $at('2026-10-30 12:00');
    $first = $at('2026-11-02 08:00');
    $last = $at('2026-11-13 23:30');
    $after = $at('2026-11-14 00:10');

    expect($before->status)->toBe(StageStatus::Upcoming)->and($before->daysLeft)->toBe(3)
        ->and($first->status)->toBe(StageStatus::InProgress)->and($first->daysLeft)->toBe(11)
        ->and($last->status)->toBe(StageStatus::InProgress)->and($last->daysLeft)->toBe(0)
        ->and($after->status)->toBe(StageStatus::Finished)->and($after->daysLeft)->toBeNull();
});

it('guarda en la caché solo las ventanas, no el estado', function () {
    TermStage::factory()->for($this->term)->create(['stage' => Stage::Proposal, 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-13']);

    $this->travelTo(CarbonImmutable::parse('2026-11-05', 'America/Ciudad_Juarez'));
    expect(termCalendarFor($this->term, $this->modality, Stage::Proposal)->status)->toBe(StageStatus::InProgress);

    expect(Cache::has(TermCalendarQuery::key($this->term->id)))->toBeTrue();

    $this->travelTo(CarbonImmutable::parse('2026-11-20', 'America/Ciudad_Juarez'));
    expect(termCalendarFor($this->term, $this->modality, Stage::Proposal)->status)->toBe(StageStatus::Finished);
});

it('invalida la caché de todas las modalidades al fijar o quitar una ventana', function () {
    $other = Modality::factory()->create();
    $set = fn (?Modality $modality, string $end) => app(SetTermStage::class)->handle($this->term, Stage::Staffing, $modality, '2026-11-02', $end);

    $set(null, '2026-11-13');
    expect(termCalendarFor($this->term, $this->modality, Stage::Staffing)->endsOn->toDateString())->toBe('2026-11-13')
        ->and(termCalendarFor($this->term, $other, Stage::Staffing)->endsOn->toDateString())->toBe('2026-11-13')
        ->and(termCalendarFor($this->term, null, Stage::Staffing)->endsOn->toDateString())->toBe('2026-11-13');

    $set(null, '2026-11-20');
    expect(termCalendarFor($this->term, $this->modality, Stage::Staffing)->endsOn->toDateString())->toBe('2026-11-20')
        ->and(termCalendarFor($this->term, $other, Stage::Staffing)->endsOn->toDateString())->toBe('2026-11-20')
        ->and(termCalendarFor($this->term, null, Stage::Staffing)->endsOn->toDateString())->toBe('2026-11-20');

    $set($this->modality, '2026-11-30');
    expect(termCalendarFor($this->term, $this->modality, Stage::Staffing)->endsOn->toDateString())->toBe('2026-11-30');

    app(RemoveTermStage::class)->handle(TermStage::where('modality_id', $this->modality->id)->sole());
    expect(termCalendarFor($this->term, $this->modality, Stage::Staffing)->endsOn->toDateString())->toBe('2026-11-20');

    app(RemoveTermStage::class)->handle(TermStage::whereNull('modality_id')->sole());
    expect(termCalendarFor($this->term, $this->modality, Stage::Staffing)->source)->toBe(StageSource::None)
        ->and(termCalendarFor($this->term, $other, Stage::Staffing)->source)->toBe(StageSource::None);
});

it('ownWindows da por modalidad solo las ventanas propias, y vacío si no tiene', function () {
    $other = Modality::factory()->create();
    app(SetTermStage::class)->handle($this->term, Stage::Proposal, null, '2027-01-04', '2027-01-15');
    app(SetTermStage::class)->handle($this->term, Stage::Scheduling, $this->modality, '2027-01-18', '2027-01-29');

    $own = app(TermCalendarQuery::class)->ownWindows($this->term, [$this->modality, $other]);

    expect(array_keys($own))->toBe([$this->modality->code, $other->code])
        ->and($own[$this->modality->code])->toHaveCount(1)
        ->and($own[$this->modality->code][0])->stage->toBe(Stage::Scheduling)->source->toBe(StageSource::Modality)
        ->and($own[$other->code])->toBe([]);
});

it('storedWindows da la general y las de modalidad de una etapa tal como están guardadas', function () {
    app(SetTermStage::class)->handle($this->term, Stage::Proposal, null, '2027-01-04', '2027-01-15');
    app(SetTermStage::class)->handle($this->term, Stage::Proposal, $this->modality, '2027-01-05', '2027-01-16');
    app(SetTermStage::class)->handle($this->term, Stage::Rooms, null, '2027-02-01', '2027-02-05');

    expect(app(TermCalendarQuery::class)->storedWindows($this->term, Stage::Proposal)->pluck('modality_id')->sort()->values()->all())
        ->toEqual(collect([null, $this->modality->id])->sort()->values()->all());
});

it('lee todas las modalidades de una sola entrada de caché por periodo', function () {
    $other = Modality::factory()->create();
    app(SetTermStage::class)->handle($this->term, Stage::Proposal, null, '2027-01-04', '2027-01-15');
    app(SetTermStage::class)->handle($this->term, Stage::Scheduling, $other, '2027-01-18', '2027-01-29');
    Cache::flush();

    $reads = [];
    Cache::spy();
    Cache::shouldReceive('remember')->andReturnUsing(function (string $key, $ttl, Closure $callback) use (&$reads) {
        $reads[] = $key;

        return $callback();
    });

    app(TermCalendarQuery::class)->ownWindows($this->term, [$this->modality, $other]);

    expect(array_unique($reads))->toBe([TermCalendarQuery::key($this->term->id)]);
});

it('copySources lista los demás periodos con ventanas, del más reciente al más antiguo', function () {
    $old = Term::factory()->create(['starts_on' => '2026-01-26', 'ends_on' => '2026-06-12']);
    $recent = Term::factory()->create(['starts_on' => '2026-08-17', 'ends_on' => '2026-12-11']);
    Term::factory()->create(['starts_on' => '2026-06-15', 'ends_on' => '2026-07-31']);
    foreach ([$old, $recent, $this->term] as $term) {
        TermStage::factory()->for($term)->create(['stage' => Stage::Proposal]);
    }

    expect(app(TermCalendarQuery::class)->copySources($this->term)->pluck('id')->all())->toBe([$recent->id, $old->id]);
});

it('copyPreview corre las ventanas por semanas completas, conserva el día y separa las modalidades inactivas', function () {
    $source = Term::factory()->create(['starts_on' => '2026-08-17', 'ends_on' => '2026-12-11']);
    $target = Term::factory()->create(['starts_on' => '2027-01-25', 'ends_on' => '2027-06-11']);
    $inactive = Modality::factory()->inactive()->create(['code' => 'M999']);
    TermStage::factory()->for($source)->create(['stage' => Stage::Rooms, 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-16']);
    TermStage::factory()->for($source)->create(['stage' => Stage::Proposal, 'modality_id' => $this->modality->id, 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-04']);
    TermStage::factory()->for($source)->create(['stage' => Stage::Proposal, 'starts_on' => '2026-08-31', 'ends_on' => '2026-09-11']);
    TermStage::factory()->for($source)->create(['stage' => Stage::Closing, 'modality_id' => $inactive->id, 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-06']);

    $preview = app(TermCalendarQuery::class)->copyPreview($target, $source);

    expect($preview['shiftDays'])->toBe(161)
        ->and(array_map(fn ($w) => [$w['stage'], $w['modality']?->id], $preview['windows']))->toBe([
            [Stage::Proposal, null], [Stage::Proposal, $this->modality->id], [Stage::Rooms, null],
        ])
        ->and($preview['windows'][0]['starts_on']->toDateString())->toBe('2027-02-08')
        ->and($preview['windows'][0]['starts_on']->dayOfWeek)->toBe(CarbonImmutable::parse('2026-08-31')->dayOfWeek)
        ->and($preview['skipped'])->toHaveCount(1)
        ->and($preview['skipped'][0]['modality']->is($inactive))->toBeTrue();
});

it('copySources no ofrece un periodo cuyas únicas ventanas son de una modalidad inactiva o borrada', function () {
    $inactive = Modality::factory()->inactive()->create();
    $deleted = Modality::factory()->create();
    $deleted->delete();
    $onlyInactive = Term::factory()->create(['starts_on' => '2026-01-26', 'ends_on' => '2026-06-12']);
    $onlyDeleted = Term::factory()->create(['starts_on' => '2026-06-15', 'ends_on' => '2026-07-31']);
    $withActive = Term::factory()->create(['starts_on' => '2026-08-17', 'ends_on' => '2026-12-11']);
    TermStage::factory()->for($onlyInactive)->create(['stage' => Stage::Rooms, 'modality_id' => $inactive->id]);
    TermStage::factory()->for($onlyDeleted)->create(['stage' => Stage::Rooms, 'modality_id' => $deleted->id]);
    TermStage::factory()->for($withActive)->create(['stage' => Stage::Rooms, 'modality_id' => $this->modality->id]);

    expect(app(TermCalendarQuery::class)->copySources($this->term)->pluck('id')->all())->toBe([$withActive->id]);
});
