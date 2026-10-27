<?php

use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermStage;
use Illuminate\Database\UniqueConstraintViolationException;

it('aplica a todos los campus una etapa sin campus y a uno solo la que tiene campus', function () {
    $term = Term::factory()->create();
    $c1 = Campus::factory()->create(['code' => 'C1']);
    $c2 = Campus::factory()->create(['code' => 'C2']);

    $global = $term->stages()->create(['stage' => Stage::CatalogValidation, 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-13']);
    $onlyC2 = $term->stages()->create(['stage' => Stage::Proposal, 'campus_id' => $c2->id, 'starts_on' => '2026-11-16', 'ends_on' => '2026-11-20']);
    $onlyC1 = $term->stages()->create(['stage' => Stage::Proposal, 'campus_id' => $c1->id, 'starts_on' => '2026-11-23', 'ends_on' => '2026-11-27']);

    expect(TermStage::appliesTo($c2)->pluck('id')->all())->toEqualCanonicalizing([$global->id, $onlyC2->id])
        ->and(TermStage::appliesTo($c1->id)->pluck('id')->all())->toEqualCanonicalizing([$global->id, $onlyC1->id]);
});

it('guarda la etapa como enum y sus fechas como fechas', function () {
    $stage = Term::factory()->create()->stages()
        ->create(['stage' => Stage::Staffing, 'starts_on' => '2026-11-30', 'ends_on' => '2026-12-04'])
        ->fresh();

    expect($stage->stage)->toBe(Stage::Staffing)
        ->and($stage->starts_on->toDateString())->toBe('2026-11-30')
        ->and($stage->ends_on->toDateString())->toBe('2026-12-04');
});

it('no repite una etapa del mismo periodo para todos los campus ni para el mismo campus', function (bool $withCampus) {
    $term = Term::factory()->create();
    $window = [
        'stage' => Stage::Proposal,
        'campus_id' => $withCampus ? Campus::factory()->create()->id : null,
        'starts_on' => '2026-11-16',
        'ends_on' => '2026-11-20',
    ];
    $term->stages()->create($window);

    expect(fn () => $term->stages()->create([...$window, 'starts_on' => '2026-11-23', 'ends_on' => '2026-11-27']))
        ->toThrow(UniqueConstraintViolationException::class);
})->with(['para todos los campus' => false, 'para un campus' => true]);

it('permite la misma etapa para todos los campus y para un campus en particular', function () {
    $term = Term::factory()->create();
    $campus = Campus::factory()->create();

    $term->stages()->create(['stage' => Stage::Proposal, 'starts_on' => '2026-11-16', 'ends_on' => '2026-11-20']);
    $term->stages()->create(['stage' => Stage::Proposal, 'campus_id' => $campus->id, 'starts_on' => '2026-11-23', 'ends_on' => '2026-11-27']);

    expect($term->stages()->count())->toBe(2);
});
