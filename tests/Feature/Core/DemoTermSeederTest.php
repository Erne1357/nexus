<?php

use App\Modules\Core\Database\Seeders\CampusSeeder;
use App\Modules\Core\Database\Seeders\DemoTermSeeder;
use App\Modules\Core\Database\Seeders\TermCalendarDemoSeeder;
use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermStage;
use App\Modules\Core\Queries\TermCalendarQuery;
use Illuminate\Support\Facades\Cache;

/*
| DemoTermSeeder: Ene-Jun 2027 trae un calendario general de 8 etapas, sin pisar uno que ya exista.
*/

it('siembra el calendario general de 20271 con las 8 etapas', function () {
    (new DemoTermSeeder)->run();

    $windows = TermStage::query()->whereNull('campus_id')->get();

    expect($windows)->toHaveCount(8)
        ->and($windows->pluck('stage')->map->value->sort()->values()->all())->toBe(collect(Stage::cases())->map->value->sort()->values()->all())
        ->and($windows->every(fn (TermStage $window) => $window->starts_on->isWeekday() && $window->ends_on >= $window->starts_on))->toBeTrue();
});

it('es idempotente y no pisa un calendario que ya existe', function () {
    (new DemoTermSeeder)->run();
    (new DemoTermSeeder)->run();

    expect(TermStage::count())->toBe(8);

    TermStage::query()->where('stage', Stage::Proposal)->update(['starts_on' => '2026-11-10']);
    (new DemoTermSeeder)->run();

    expect(TermStage::query()->where('stage', Stage::Proposal)->sole()->starts_on->toDateString())->toBe('2026-11-10');
});

it('no siembra nada si 20271 ya tiene alguna ventana', function () {
    Term::factory()->create(['code' => '20271']);
    TermStage::factory()->for(Term::where('code', '20271')->first())->create(['stage' => Stage::Rooms]);

    (new DemoTermSeeder)->run();

    expect(TermStage::count())->toBe(1);
});

it('olvida la caché del calendario al sembrarlo', function () {
    (new DemoTermSeeder)->run();
    $term = Term::where('code', '20271')->sole();
    TermStage::query()->delete();
    Cache::flush();
    expect(collect(app(TermCalendarQuery::class)->for($term, null))->whereNotNull('startsOn'))->toHaveCount(0);

    (new DemoTermSeeder)->run();

    expect(collect(app(TermCalendarQuery::class)->for($term, null))->whereNotNull('startsOn'))->toHaveCount(8);
});

/*
| TermCalendarDemoSeeder: calendarios del masivo.
*/

it('siembra el calendario general de 20263 y las ventanas de Campus I en 20271', function () {
    $this->seed(CampusSeeder::class);
    (new DemoTermSeeder)->run();
    (new TermCalendarDemoSeeder)->run();

    $c1 = Campus::where('code', 'C1')->sole();
    $general = Term::where('code', '20263')->sole();
    $own = Term::where('code', '20271')->sole();

    expect(TermStage::where('term_id', $general->getKey())->whereNull('campus_id')->count())->toBe(8)
        ->and(TermStage::where('term_id', $own->getKey())->where('campus_id', $c1->getKey())->count())->toBe(3);
});

it('el calendario del masivo es idempotente, no pisa y se salta un Campus I inactivo', function () {
    $this->seed(CampusSeeder::class);
    Campus::where('code', 'C1')->update(['is_active' => false]);
    (new DemoTermSeeder)->run();
    (new TermCalendarDemoSeeder)->run();
    TermStage::query()->where('stage', Stage::Rooms)->update(['starts_on' => '2026-06-09']);
    (new TermCalendarDemoSeeder)->run();

    expect(TermStage::where('term_id', Term::where('code', '20263')->value('id'))->count())->toBe(8)
        ->and(TermStage::whereNotNull('campus_id')->count())->toBe(0)
        ->and(TermStage::where('stage', Stage::Rooms)->where('starts_on', '2026-06-09')->exists())->toBeTrue();
});

it('el calendario del masivo se niega a correr en producción', function () {
    $this->app->detectEnvironment(fn (): string => 'production');

    expect(fn () => (new TermCalendarDemoSeeder)->run())->toThrow(RuntimeException::class);
});
