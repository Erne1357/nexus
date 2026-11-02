<?php

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/*
| Integridad de `catalog_reviews` (docs/dominio/modelo-de-datos.md, "CatalogValidation"): los cuatro
| catálogos generales se revisan una vez por periodo y departamento para todo el Tec (`campus_id` nulo)
| y los salones una vez por campus (D2, confirmado el 1 de noviembre de 2026). Un CHECK lo amarra y la
| unicidad cuenta el campus nulo como un valor.
*/

it('nace pendiente y conoce su periodo, campus, departamento y quién la validó', function () {
    $review = CatalogReview::factory()->rooms()->validated()->create()->fresh();

    expect($review->catalog)->toBe(Catalog::Rooms)
        ->and($review->status)->toBe(ReviewStatus::Validated)
        ->and($review->term)->toBeInstanceOf(Term::class)
        ->and($review->campus)->toBeInstanceOf(Campus::class)
        ->and($review->department)->toBeInstanceOf(Department::class)
        ->and($review->validator)->toBeInstanceOf(User::class)
        ->and($review->validated_at)->not->toBeNull();

    $pending = CatalogReview::query()->create([
        'term_id' => $review->term_id,
        'department_id' => $review->department_id,
        'catalog' => Catalog::Teachers,
    ])->fresh();

    expect($pending->status)->toBe(ReviewStatus::Pending)
        ->and($pending->campus_id)->toBeNull();
});

it('rechaza una revisión de salones sin campus', function () {
    expect(fn () => CatalogReview::factory()->create(['catalog' => Catalog::Rooms, 'campus_id' => null]))
        ->toThrow(QueryException::class, 'catalog_reviews_campus_check');
});

it('rechaza una revisión general con campus', function () {
    expect(fn () => CatalogReview::factory()->create(['catalog' => Catalog::Subjects, 'campus_id' => Campus::factory()->create()->id]))
        ->toThrow(QueryException::class, 'catalog_reviews_campus_check');
});

it('no repite una revisión general del mismo periodo, departamento y catálogo, aunque el campus sea nulo', function () {
    $review = CatalogReview::factory()->general(Catalog::Careers)->inReview()->create();

    expect($review->fresh()->status)->toBe(ReviewStatus::InReview)
        ->and(fn () => CatalogReview::factory()->create($review->only(['term_id', 'department_id', 'catalog'])))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('no repite la revisión de salones en el mismo campus; en otro campus sí', function () {
    $review = CatalogReview::factory()->rooms()->create();

    CatalogReview::factory()->rooms()->create($review->only(['term_id', 'department_id']));

    expect(fn () => CatalogReview::factory()->rooms()->create($review->only(['term_id', 'department_id', 'campus_id'])))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('rechaza un estado o un catálogo que no existen', function (string $column, string $value, string $constraint) {
    $review = CatalogReview::factory()->create();

    expect(fn () => DB::table('catalog_reviews')->where('id', $review->id)->update([$column => $value]))
        ->toThrow(QueryException::class, $constraint);
})->with([
    'estado' => ['status', 'approved', 'catalog_reviews_status_check'],
    'catálogo' => ['catalog', 'buildings', 'catalog_reviews_catalog_check'],
]);
