<?php

use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\TermQuery;
use Illuminate\Database\UniqueConstraintViolationException;

/*
| En qué modalidades se abrió cada periodo (M4; docs/dominio/modelo-de-datos.md, `term_openings`): una apertura por
| periodo y modalidad, con quién y cuándo la hizo. La ficha del periodo la lee con TermQuery::openings().
*/

it('guarda quién y cuándo abrió el periodo en la modalidad', function () {
    $opening = TermOpening::factory()->create(['opened_at' => '2026-11-01 09:30:00'])->fresh();

    expect($opening->term)->toBeInstanceOf(Term::class)
        ->and($opening->modality->code)->toBe('PRES')
        ->and($opening->opener)->toBeInstanceOf(User::class)
        ->and($opening->opened_at->toDateTimeString())->toBe('2026-11-01 09:30:00');
});

it('M4: abre un periodo una sola vez por modalidad; otra modalidad u otro periodo sí', function () {
    $opening = TermOpening::factory()->create();

    TermOpening::factory()->create(['term_id' => $opening->term_id, 'modality_id' => Modality::factory()->create()->id]);
    TermOpening::factory()->create(['modality_id' => $opening->modality_id]);

    expect(fn () => TermOpening::factory()->create(['term_id' => $opening->term_id, 'modality_id' => $opening->modality_id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('el periodo conoce sus aperturas', function () {
    $term = Term::factory()->create();
    $openings = collect([
        TermOpening::factory()->for($term)->create(),
        TermOpening::factory()->for($term)->for(Modality::factory())->create(),
    ]);
    TermOpening::factory()->create();

    expect($term->openings()->pluck('id')->all())->toEqualCanonicalizing($openings->pluck('id')->all());
});

it('TermQuery da las aperturas del periodo por clave de modalidad, con la modalidad y quién abrió cargados', function () {
    $term = Term::factory()->create();
    $pres = TermOpening::factory()->for($term)->create();
    $ead = TermOpening::factory()->for($term)->for(Modality::factory()->state(['code' => 'EAD9']))->create();
    TermOpening::factory()->create();

    $openings = app(TermQuery::class)->openings($term);

    expect($openings->pluck('id')->all())->toBe([$ead->id, $pres->id])
        ->and($openings->every(fn (TermOpening $opening): bool => $opening->relationLoaded('modality') && $opening->relationLoaded('opener')))->toBeTrue();
});
