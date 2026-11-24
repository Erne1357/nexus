<?php

use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\TermQuery;
use Carbon\CarbonImmutable;

/*
| TP-03: el periodo en curso (TermQuery::inProgress), el de consulta por omisión de la credencial.
*/

beforeEach(function () {
    $this->aug = Term::factory()->create(['code' => '20263', 'name' => 'Ago-Dic 2026', 'starts_on' => '2026-08-17', 'ends_on' => '2026-12-11']);
    $this->jan = Term::factory()->create(['code' => '20271', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-25', 'ends_on' => '2027-05-28']);
    $this->summer = Term::factory()->create(['code' => '20262', 'name' => 'Verano 2026', 'starts_on' => '2026-06-22', 'ends_on' => '2026-08-07']);
    $this->spring = Term::factory()->create(['code' => '20261', 'name' => 'Ene-Jun 2026', 'starts_on' => '2026-01-26', 'ends_on' => '2026-06-05']);
});

function inProgressOn(string $day): ?Term
{
    return app(TermQuery::class)->inProgress(CarbonImmutable::parse($day, 'America/Ciudad_Juarez'));
}

it('TP-03: en el semestre, el en curso es el que contiene el día', function () {
    expect(inProgressOn('2026-11-23')?->code)->toBe('20263')
        ->and(inProgressOn('2027-03-01')?->code)->toBe('20271');
});

it('TP-03: entre semestres es el que acaba de terminar, no el que viene', function () {
    expect(inProgressOn('2026-12-20')?->code)->toBe('20263')
        ->and(inProgressOn('2027-07-01')?->code)->toBe('20271');
});

it('TP-03: el verano nunca es el de omisión', function () {
    expect(inProgressOn('2026-07-01')?->code)->toBe('20261');
});

it('TP-03: si dos periodos contienen el día, gana el de código mayor', function () {
    $this->aug->update(['ends_on' => '2027-02-01']);

    expect(inProgressOn('2027-01-30')?->code)->toBe('20271');
});

it('TP-03: sin periodos que hayan empezado, ninguno', function () {
    Term::query()->delete();

    expect(inProgressOn('2026-11-23'))->toBeNull();
});
