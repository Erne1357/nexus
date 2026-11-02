<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\TermQuery;
use Illuminate\Database\UniqueConstraintViolationException;

/*
| En qué campus se abrió cada periodo (docs/dominio/modelo-de-datos.md, `term_openings`): una apertura
| por periodo y campus, con quién y cuándo la hizo. La ficha del periodo la lee con TermQuery::openings().
*/

it('guarda quién y cuándo abrió el periodo en el campus', function () {
    $opening = TermOpening::factory()->create(['opened_at' => '2026-11-01 09:30:00'])->fresh();

    expect($opening->term)->toBeInstanceOf(Term::class)
        ->and($opening->campus)->toBeInstanceOf(Campus::class)
        ->and($opening->opener)->toBeInstanceOf(User::class)
        ->and($opening->opened_at->toDateTimeString())->toBe('2026-11-01 09:30:00');
});

it('abre un periodo una sola vez por campus; otro campus u otro periodo sí', function () {
    $opening = TermOpening::factory()->create();

    TermOpening::factory()->create(['term_id' => $opening->term_id]);
    TermOpening::factory()->create(['campus_id' => $opening->campus_id]);

    expect(fn () => TermOpening::factory()->create(['term_id' => $opening->term_id, 'campus_id' => $opening->campus_id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('el periodo conoce sus aperturas', function () {
    $term = Term::factory()->create();
    $openings = TermOpening::factory()->count(2)->for($term)->create();
    TermOpening::factory()->create();

    expect($term->openings()->pluck('id')->all())->toEqualCanonicalizing($openings->pluck('id')->all());
});

it('TermQuery da las aperturas del periodo por código de campus, con campus y quién abrió cargados', function () {
    $term = Term::factory()->create();
    $c2 = TermOpening::factory()->for($term)->for(Campus::factory()->state(['code' => 'C2']))->create();
    $c1 = TermOpening::factory()->for($term)->for(Campus::factory()->state(['code' => 'C1']))->create();
    TermOpening::factory()->create();

    $openings = app(TermQuery::class)->openings($term);

    expect($openings->pluck('id')->all())->toBe([$c1->id, $c2->id])
        ->and($openings->every(fn (TermOpening $opening): bool => $opening->relationLoaded('campus') && $opening->relationLoaded('opener')))->toBeTrue();
});
