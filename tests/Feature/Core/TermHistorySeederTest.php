<?php

use App\Modules\Core\Database\Seeders\TermHistorySeeder;
use App\Modules\Core\Models\Term;

/*
| TermHistorySeeder: los códigos del SII desde un año hasta 20273, sin pisar lo que ya existe.
*/

it('crea todos los códigos desde el año pedido hasta 20273', function () {
    (new TermHistorySeeder)->run(2025);

    expect(Term::query()->orderBy('code')->pluck('code')->all())
        ->toBe(['20251', '20252', '20253', '20261', '20262', '20263', '20271', '20272', '20273'])
        ->and(Term::query()->where('is_current', true)->count())->toBe(0)
        ->and(Term::where('code', '20262')->sole()->name)->toBe('Verano 2026')
        ->and(Term::where('code', '20271')->sole()->starts_on->toDateString())->toBe('2027-01-22');
});

it('empieza en 2014 por omisión', function () {
    (new TermHistorySeeder)->run();

    expect(Term::query()->min('code'))->toBe('20141')
        ->and(Term::count())->toBe(14 * 3)
        ->and(Term::where('code', '20272')->sole()->stages()->count())->toBe(0);
});

it('no toca un periodo que ya existe', function () {
    Term::factory()->create(['code' => '20263', 'name' => 'Mi periodo', 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-01', 'is_current' => true]);

    (new TermHistorySeeder)->run(2026);

    $term = Term::where('code', '20263')->sole();

    expect($term->name)->toBe('Mi periodo')
        ->and($term->starts_on->toDateString())->toBe('2026-09-01')
        ->and($term->is_current)->toBeTrue()
        ->and(Term::where('is_current', true)->count())->toBe(1);
});

it('le da un nombre larguísimo a 20252 solo si lo crea', function () {
    (new TermHistorySeeder)->run(2025);

    expect(Term::where('code', '20252')->sole()->name)->toBe(TermHistorySeeder::LONG_NAME)
        ->and(strlen(TermHistorySeeder::LONG_NAME))->toBeGreaterThan(80);

    Term::where('code', '20252')->update(['name' => 'Renombrado']);
    (new TermHistorySeeder)->run(2025);

    expect(Term::where('code', '20252')->sole()->name)->toBe('Renombrado');
});

it('es idempotente', function () {
    (new TermHistorySeeder)->run(2025);
    $count = Term::count();
    (new TermHistorySeeder)->run(2025);

    expect(Term::count())->toBe($count);
});

it('se niega a correr en producción', function () {
    $this->app->detectEnvironment(fn (): string => 'production');

    expect(fn () => (new TermHistorySeeder)->run())->toThrow(RuntimeException::class);
});
