<?php

use App\Modules\Core\Database\Seeders\DemoTermSeeder;
use App\Modules\Core\Enums\Stage;
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
