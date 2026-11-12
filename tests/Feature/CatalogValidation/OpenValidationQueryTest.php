<?php

use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Queries\OpenValidationQuery;
use App\Modules\Core\Models\Term;

/*
| MN-03 (confirmado el 12 de noviembre de 2026): la validación está abierta en un periodo mientras tenga al menos
| una revisión de catálogo pendiente o en revisión. Decide si el menú muestra Validar catálogos y Avance de
| validación a quien no es admin.
*/

beforeEach(function () {
    $this->term = Term::factory()->create(['name' => 'Ene-Jun 2027']);
});

it('MN-03: con una revisión pendiente o en revisión, la validación está abierta', function (ReviewStatus $status) {
    CatalogReview::factory()->for($this->term)->validated()->create();
    CatalogReview::factory()->for($this->term)->create(['status' => $status]);

    expect(app(OpenValidationQuery::class)->existsIn($this->term))->toBeTrue()
        ->and(app(OpenValidationQuery::class)->whyUnavailable($this->term))->toBeNull();
})->with(['pendiente' => [ReviewStatus::Pending], 'en revisión' => [ReviewStatus::InReview]]);

it('MN-03: con todas las revisiones validadas, o sin ninguna, no está abierta', function (bool $withReviews) {
    if ($withReviews) {
        CatalogReview::factory()->for($this->term)->validated()->count(2)->create();
    }

    expect(app(OpenValidationQuery::class)->existsIn($this->term))->toBeFalse()
        ->and(app(OpenValidationQuery::class)->whyUnavailable($this->term))->toBe('No hay validación abierta en Ene-Jun 2027');
})->with(['todas validadas' => [true], 'sin revisiones' => [false]]);

it('MN-03: solo cuentan las revisiones del periodo', function () {
    CatalogReview::factory()->create();

    expect(app(OpenValidationQuery::class)->existsIn($this->term))->toBeFalse();
});

it('MN-03: sin periodo elegido no está disponible', function () {
    expect(app(OpenValidationQuery::class)->whyUnavailable(null))->toBe('No hay un periodo elegido');
});
