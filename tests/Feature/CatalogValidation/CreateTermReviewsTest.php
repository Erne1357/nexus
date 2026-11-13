<?php

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Actions\CreateTermReviews;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Models\Term;

/*
| CreateTermReviews (CV9): la pieza de escritura de OpenCatalogValidation. Crea, por cada departamento activo, una revisión
| de cada catálogo dado en el periodo, sin campus (la de salones es una para todos, decisión 6); idempotente.
*/

function createTermReviews(Term $term, array $catalogs = []): int
{
    return app(CreateTermReviews::class)->handle($term, $catalogs === [] ? Catalog::cases() : $catalogs);
}

/**
 * Las revisiones del periodo como «departamento catálogo», ordenadas.
 *
 * @return list<string>
 */
function reviewKeysOf(Term $term): array
{
    return CatalogReview::query()->where('term_id', $term->id)->with('department')->get()
        ->map(fn (CatalogReview $review): string => "{$review->department->code} {$review->catalog->value}")
        ->sort()->values()->all();
}

it('crea las revisiones de los catálogos dados en cada departamento activo, sin campus, pendientes', function () {
    $term = Term::factory()->create();
    Department::factory()->create(['code' => 'D1']);
    Department::factory()->create(['code' => 'D2']);

    expect(createTermReviews($term, [Catalog::Teachers, Catalog::Rooms]))->toBe(4)
        ->and(reviewKeysOf($term))->toBe(['D1 rooms', 'D1 teachers', 'D2 rooms', 'D2 teachers'])
        ->and(CatalogReview::query()->pluck('status')->unique()->all())->toBe([ReviewStatus::Pending])
        ->and(CatalogReview::query()->whereNotNull('campus_id')->count())->toBe(0);
});

it('no crea revisiones de departamentos inactivos ni borrados', function () {
    $term = Term::factory()->create();
    Department::factory()->create(['code' => 'D1']);
    Department::factory()->inactive()->create();
    Department::factory()->create()->delete();

    expect(createTermReviews($term))->toBe(5)
        ->and(reviewKeysOf($term))->each->toStartWith('D1 ');
});

it('es idempotente: la segunda vez no crea nada; un catálogo nuevo o un departamento nuevo reciben solo lo suyo', function () {
    $term = Term::factory()->create();
    Department::factory()->create(['code' => 'D1']);
    createTermReviews($term, [Catalog::Teachers]);

    expect(createTermReviews($term, [Catalog::Teachers]))->toBe(0)
        ->and(createTermReviews($term, [Catalog::Teachers, Catalog::Careers]))->toBe(1);

    Department::factory()->create(['code' => 'D2']);

    expect(createTermReviews($term, [Catalog::Teachers, Catalog::Careers]))->toBe(2)
        ->and(CatalogReview::count())->toBe(4);
});

it('no toca las revisiones de otro periodo', function () {
    Department::factory()->create();
    createTermReviews(Term::factory()->create());

    expect(createTermReviews(Term::factory()->create()))->toBe(5);
});
