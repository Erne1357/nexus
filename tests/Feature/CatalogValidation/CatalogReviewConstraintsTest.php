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
| Integridad de `catalog_reviews` (docs/dominio/modelo-de-datos.md, "CatalogValidation"): los cinco
| catálogos se revisan una vez por periodo y departamento para todo el Tec, también los salones de todos los campus
| (decisión 6, confirmada el 12 de noviembre de 2026). Un CHECK exige `campus_id` nulo y la unicidad cuenta el campus
| nulo como un valor.
*/

it('nace pendiente y conoce su periodo, departamento y quién la validó', function () {
    $review = CatalogReview::factory()->rooms()->validated()->create()->fresh();

    expect($review->catalog)->toBe(Catalog::Rooms)
        ->and($review->status)->toBe(ReviewStatus::Validated)
        ->and($review->term)->toBeInstanceOf(Term::class)
        ->and($review->campus_id)->toBeNull()
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

it('rechaza una revisión con campus, también la de salones', function (Catalog $catalog) {
    expect(fn () => CatalogReview::factory()->create(['catalog' => $catalog, 'campus_id' => Campus::factory()->create()->id]))
        ->toThrow(QueryException::class, 'catalog_reviews_campus_check');
})->with([Catalog::Rooms, Catalog::Subjects]);

it('no repite una revisión del mismo periodo, departamento y catálogo, aunque el campus sea nulo', function (Catalog $catalog) {
    $review = CatalogReview::factory()->create(['catalog' => $catalog, 'status' => ReviewStatus::InReview]);

    expect($review->fresh()->status)->toBe(ReviewStatus::InReview)
        ->and(fn () => CatalogReview::factory()->create($review->only(['term_id', 'department_id', 'catalog'])))
        ->toThrow(UniqueConstraintViolationException::class);
})->with([Catalog::Careers, Catalog::Rooms]);

it('rechaza un estado o un catálogo que no existen', function (string $column, string $value, string $constraint) {
    $review = CatalogReview::factory()->create();

    expect(fn () => DB::table('catalog_reviews')->where('id', $review->id)->update([$column => $value]))
        ->toThrow(QueryException::class, $constraint);
})->with([
    'estado' => ['status', 'approved', 'catalog_reviews_status_check'],
    'catálogo' => ['catalog', 'buildings', 'catalog_reviews_catalog_check'],
]);
