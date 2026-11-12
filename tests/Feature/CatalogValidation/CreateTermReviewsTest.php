<?php

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Actions\CreateTermReviews;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\ModalityProfile;
use App\Modules\Core\Models\Term;

/*
| Al abrir un periodo en una modalidad (M4), CatalogValidation crea las revisiones que falten (supuesto por confirmar;
| hasta que el admin elija qué catálogos se validan, MS15): las cinco de cada departamento activo, una vez por periodo
| para todo el Tec, sin campus; la de salones solo si el perfil de la modalidad que se abre usa salones, y es una para
| todos los campus (decisión 6, confirmada el 12 de noviembre de 2026). Todas nacen pendientes.
*/

function createTermReviews(Term $term, Modality $modality): int
{
    return app(CreateTermReviews::class)->handle($term, $modality);
}

/**
 * Las revisiones del periodo como «departamento catálogo campus» (campus «-» si es general), ordenadas.
 *
 * @return list<string>
 */
function reviewKeysOf(Term $term): array
{
    return CatalogReview::query()->where('term_id', $term->id)->with(['department', 'campus'])->get()
        ->map(fn (CatalogReview $review): string => "{$review->department->code} {$review->catalog->value} ".($review->campus->code ?? '-'))
        ->sort()->values()->all();
}

function modalityWithoutRooms(): Modality
{
    return Modality::factory()->create(['settings' => [...ModalityProfile::DEFAULTS, 'uses_rooms' => false]]);
}

beforeEach(function () {
    $this->presencial = modalityOf('PRES');
});

it('crea las cinco revisiones de cada departamento activo, sin campus, pendientes', function () {
    $term = Term::factory()->create();
    Department::factory()->create(['code' => 'D1']);
    Department::factory()->create(['code' => 'D2']);

    expect(createTermReviews($term, $this->presencial))->toBe(10)
        ->and(reviewKeysOf($term))->toBe([
            'D1 careers -', 'D1 rooms -', 'D1 study_plans -', 'D1 subjects -', 'D1 teachers -',
            'D2 careers -', 'D2 rooms -', 'D2 study_plans -', 'D2 subjects -', 'D2 teachers -',
        ])
        ->and(CatalogReview::query()->pluck('status')->unique()->all())->toBe([ReviewStatus::Pending]);
});

it('no crea revisiones de departamentos inactivos ni borrados', function () {
    $term = Term::factory()->create();
    Department::factory()->create(['code' => 'D1']);
    Department::factory()->inactive()->create();
    Department::factory()->create()->delete();

    expect(createTermReviews($term, $this->presencial))->toBe(5)
        ->and(reviewKeysOf($term))->each->toStartWith('D1 ');
});

it('es idempotente: la segunda vez no crea nada; un departamento nuevo recibe solo las suyas', function () {
    $term = Term::factory()->create();
    Department::factory()->create(['code' => 'D1']);
    createTermReviews($term, $this->presencial);

    expect(createTermReviews($term, $this->presencial))->toBe(0);

    Department::factory()->create(['code' => 'D2']);

    expect(createTermReviews($term, $this->presencial))->toBe(5)
        ->and(CatalogReview::count())->toBe(10);
});

it('en otra modalidad con salones no crea otra de salones: es una por departamento y periodo', function () {
    $term = Term::factory()->create();
    Department::factory()->create(['code' => 'D1']);
    createTermReviews($term, $this->presencial);

    expect(createTermReviews($term, Modality::factory()->create()))->toBe(0)
        ->and(reviewKeysOf($term))->toBe(['D1 careers -', 'D1 rooms -', 'D1 study_plans -', 'D1 subjects -', 'D1 teachers -']);
});

it('la de salones se crea con la primera modalidad abierta que usa salones', function () {
    $term = Term::factory()->create();
    Department::factory()->create(['code' => 'D1']);
    createTermReviews($term, modalityWithoutRooms());

    expect(createTermReviews($term, $this->presencial))->toBe(1)
        ->and(reviewKeysOf($term))->toContain('D1 rooms -');
});

it('en una modalidad cuyo perfil no usa salones crea solo las generales', function () {
    $term = Term::factory()->create();
    Department::factory()->create(['code' => 'D1']);

    expect(createTermReviews($term, modalityWithoutRooms()))->toBe(4)
        ->and(CatalogReview::query()->where('catalog', Catalog::Rooms)->exists())->toBeFalse();
});

it('handleRooms crea solo la de salones de cada departamento activo que no la tenga', function () {
    $term = Term::factory()->create();
    Department::factory()->create(['code' => 'D1']);
    createTermReviews($term, modalityWithoutRooms());

    expect(app(CreateTermReviews::class)->handleRooms($term))->toBe(1)
        ->and(app(CreateTermReviews::class)->handleRooms($term))->toBe(0)
        ->and(reviewKeysOf($term))->toContain('D1 rooms -');
});

it('no toca las revisiones de otro periodo', function () {
    Department::factory()->create();
    createTermReviews(Term::factory()->create(), $this->presencial);

    expect(createTermReviews(Term::factory()->create(), $this->presencial))->toBe(5);
});
