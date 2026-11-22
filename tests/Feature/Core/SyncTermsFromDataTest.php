<?php

use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\TermQuery;
use App\Modules\Students\Models\KardexRecord;
use Spatie\Activitylog\Models\Activity;

/*
| TP-01: core:sync-terms-from-data da de alta los periodos válidos que aparecen en los datos; TP-02: el selector
| solo ofrece los recientes.
*/

function kardexWith(array $codes): void
{
    foreach ($codes as $code) {
        KardexRecord::factory()->create(['term_code' => $code]);
    }
}

it('crea los periodos que faltan con nombre y fechas por sufijo (TP-01)', function () {
    kardexWith(['19911', '20012', '20103']);

    $this->artisan('core:sync-terms-from-data')->assertSuccessful();

    $term = Term::query()->where('code', '20012')->sole();
    expect($term->name)->toBe('Verano 2001')
        ->and($term->starts_on->toDateString())->toBe('2001-06-22')
        ->and($term->ends_on->toDateString())->toBe('2001-08-07')
        ->and($term->is_current)->toBeFalse();

    $first = Term::query()->where('code', '19911')->sole();
    expect($first->name)->toBe('Ene-Jun 1991')
        ->and($first->starts_on->toDateString())->toBe('1991-01-22')
        ->and($first->ends_on->toDateString())->toBe('1991-06-05');

    $last = Term::query()->where('code', '20103')->sole();
    expect($last->name)->toBe('Ago-Dic 2010')
        ->and($last->starts_on->toDateString())->toBe('2010-08-17')
        ->and($last->ends_on->toDateString())->toBe('2010-12-11');
});

it('no duplica ni toca los existentes en una segunda corrida (TP-01)', function () {
    Term::factory()->create(['code' => '20111', 'name' => 'Nombre propio']);
    kardexWith(['20111', '20112']);

    $this->artisan('core:sync-terms-from-data')->assertSuccessful();
    $this->artisan('core:sync-terms-from-data')->assertSuccessful();

    expect(Term::query()->where('code', '20111')->count())->toBe(1)
        ->and(Term::query()->where('code', '20111')->value('name'))->toBe('Nombre propio')
        ->and(Term::query()->where('code', '20112')->count())->toBe(1);
});

it('ignora los códigos que no son periodos reales (TP-01)', function () {
    kardexWith(['19001', '19721', '20121']);

    $this->artisan('core:sync-terms-from-data')
        ->expectsOutputToContain('Descartados como basura: 2')
        ->assertSuccessful();

    expect(Term::query()->pluck('code')->all())->toBe(['20121']);
});

it('con --dry-run cuenta sin escribir (TP-01)', function () {
    kardexWith(['20131', '20132', '20133']);

    $this->artisan('core:sync-terms-from-data --dry-run')
        ->expectsOutputToContain('Crearía Ene-Jun: 1')
        ->assertSuccessful();

    expect(Term::query()->count())->toBe(0);
});

it('deja bitácora de cada periodo creado (TP-01)', function () {
    kardexWith(['20141']);

    $this->artisan('core:sync-terms-from-data')->assertSuccessful();

    expect(Activity::query()->where('description', 'Dio de alta el periodo 20141')->count())->toBe(1);
});

it('el selector ofrece desde 2020 y el actual aunque sea anterior (TP-02)', function () {
    Term::factory()->create(['code' => '20193', 'starts_on' => '2019-08-17', 'ends_on' => '2019-12-11']);
    Term::factory()->create(['code' => '20201', 'starts_on' => '2020-01-22', 'ends_on' => '2020-06-05']);
    Term::factory()->create(['code' => '20211', 'starts_on' => '2021-01-22', 'ends_on' => '2021-06-05']);

    expect(app(TermQuery::class)->selectable()->pluck('code')->all())->toBe(['20211', '20201']);

    Term::query()->where('code', '20193')->update(['is_current' => true]);

    expect(app(TermQuery::class)->selectable()->pluck('code')->all())->toBe(['20211', '20201', '20193']);
});
