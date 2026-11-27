<?php

use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermStage;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

it('M4: aplica a todas las modalidades una etapa sin modalidad y a una sola la que tiene modalidad', function () {
    $term = Term::factory()->create();
    $m1 = Modality::factory()->create(['code' => 'M001']);
    $m2 = Modality::factory()->create(['code' => 'M002']);

    $global = $term->stages()->create(['stage' => Stage::CatalogValidation, 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-13']);
    $onlyM2 = $term->stages()->create(['stage' => Stage::Proposal, 'modality_id' => $m2->id, 'starts_on' => '2026-11-16', 'ends_on' => '2026-11-20']);
    $onlyM1 = $term->stages()->create(['stage' => Stage::Proposal, 'modality_id' => $m1->id, 'starts_on' => '2026-11-23', 'ends_on' => '2026-11-27']);

    expect(TermStage::appliesTo($m2)->pluck('id')->all())->toEqualCanonicalizing([$global->id, $onlyM2->id])
        ->and(TermStage::appliesTo($m1->id)->pluck('id')->all())->toEqualCanonicalizing([$global->id, $onlyM1->id]);
});

it('guarda la etapa como enum y sus fechas como fechas', function () {
    $stage = Term::factory()->create()->stages()
        ->create(['stage' => Stage::Staffing, 'starts_on' => '2026-11-30', 'ends_on' => '2026-12-04'])
        ->fresh();

    expect($stage->stage)->toBe(Stage::Staffing)
        ->and($stage->starts_on->toDateString())->toBe('2026-11-30')
        ->and($stage->ends_on->toDateString())->toBe('2026-12-04');
});

it('M4: no repite una etapa del mismo periodo para todas las modalidades ni para la misma modalidad', function (bool $withModality) {
    $term = Term::factory()->create();
    $window = [
        'stage' => Stage::Proposal,
        'modality_id' => $withModality ? Modality::factory()->create()->id : null,
        'starts_on' => '2026-11-16',
        'ends_on' => '2026-11-20',
    ];
    $term->stages()->create($window);

    expect(fn () => $term->stages()->create([...$window, 'starts_on' => '2026-11-23', 'ends_on' => '2026-11-27']))
        ->toThrow(UniqueConstraintViolationException::class);
})->with(['para todas las modalidades' => false, 'para una modalidad' => true]);

it('permite la misma etapa para todas las modalidades y para una modalidad en particular', function () {
    $term = Term::factory()->create();
    $modality = Modality::factory()->create();

    $term->stages()->create(['stage' => Stage::Proposal, 'starts_on' => '2026-11-16', 'ends_on' => '2026-11-20']);
    $term->stages()->create(['stage' => Stage::Proposal, 'modality_id' => $modality->id, 'starts_on' => '2026-11-23', 'ends_on' => '2026-11-27']);

    expect($term->stages()->count())->toBe(2);
});

it('rechaza una etapa que termina antes de empezar', function () {
    expect(fn () => Term::factory()->create()->stages()->create(['stage' => Stage::Closing, 'starts_on' => '2026-12-11', 'ends_on' => '2026-12-07']))
        ->toThrow(QueryException::class, 'term_stages_dates_check');
});
