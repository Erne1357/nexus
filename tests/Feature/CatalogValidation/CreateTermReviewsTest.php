<?php

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Actions\CreateTermReviews;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Events\TermOpened;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\Term;

/*
| Al abrir un periodo en un campus, CatalogValidation crea las revisiones que falten (S-A6, supuesto por
| confirmar): las cuatro generales de cada departamento activo, una vez por periodo para todo el Tec, y la
| de salones de cada departamento activo en ese campus si su perfil usa salones (D2, confirmado el 1 de
| noviembre de 2026). Todas nacen pendientes.
*/

function createTermReviews(Term $term, Campus $campus): int
{
    return app(CreateTermReviews::class)->handle($term, $campus);
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

function campusWithoutRooms(string $code): Campus
{
    return Campus::factory()->create(['code' => $code, 'settings' => [...CampusProfile::DEFAULTS, 'uses_rooms' => false]]);
}

it('crea las cuatro generales y la de salones del campus por cada departamento activo, pendientes', function () {
    $term = Term::factory()->create();
    $c2 = Campus::factory()->create(['code' => 'C2']);
    Department::factory()->create(['code' => 'D1']);
    Department::factory()->create(['code' => 'D2']);

    expect(createTermReviews($term, $c2))->toBe(10)
        ->and(reviewKeysOf($term))->toBe([
            'D1 careers -', 'D1 rooms C2', 'D1 study_plans -', 'D1 subjects -', 'D1 teachers -',
            'D2 careers -', 'D2 rooms C2', 'D2 study_plans -', 'D2 subjects -', 'D2 teachers -',
        ])
        ->and(CatalogReview::query()->pluck('status')->unique()->all())->toBe([ReviewStatus::Pending]);
});

it('no crea revisiones de departamentos inactivos ni borrados', function () {
    $term = Term::factory()->create();
    Department::factory()->create(['code' => 'D1']);
    Department::factory()->inactive()->create();
    Department::factory()->create()->delete();

    expect(createTermReviews($term, Campus::factory()->create(['code' => 'C2'])))->toBe(5)
        ->and(reviewKeysOf($term))->each->toStartWith('D1 ');
});

it('es idempotente: la segunda vez no crea nada; un departamento nuevo recibe solo las suyas', function () {
    $term = Term::factory()->create();
    $c2 = Campus::factory()->create(['code' => 'C2']);
    Department::factory()->create(['code' => 'D1']);
    createTermReviews($term, $c2);

    expect(createTermReviews($term, $c2))->toBe(0);

    Department::factory()->create(['code' => 'D2']);

    expect(createTermReviews($term, $c2))->toBe(5)
        ->and(CatalogReview::count())->toBe(10);
});

it('en un segundo campus crea solo la de salones de ese campus: las generales ya existen', function () {
    $term = Term::factory()->create();
    Department::factory()->create(['code' => 'D1']);
    createTermReviews($term, Campus::factory()->create(['code' => 'C2']));

    expect(createTermReviews($term, Campus::factory()->create(['code' => 'C1'])))->toBe(1)
        ->and(reviewKeysOf($term))->toBe(['D1 careers -', 'D1 rooms C1', 'D1 rooms C2', 'D1 study_plans -', 'D1 subjects -', 'D1 teachers -']);
});

it('en un campus cuyo perfil no usa salones crea solo las generales', function () {
    $term = Term::factory()->create();
    Department::factory()->create(['code' => 'D1']);

    expect(createTermReviews($term, campusWithoutRooms('EAD')))->toBe(4)
        ->and(CatalogReview::query()->where('catalog', Catalog::Rooms)->exists())->toBeFalse();
});

it('no toca las revisiones de otro periodo', function () {
    Department::factory()->create();
    $campus = Campus::factory()->create();
    createTermReviews(Term::factory()->create(), $campus);

    expect(createTermReviews(Term::factory()->create(), $campus))->toBe(5);
});

describe('al oír TermOpened', function () {
    it('reporta cuántas revisiones creó, en plural', function () {
        Department::factory()->count(2)->create();
        $event = new TermOpened(Term::factory()->create(), Campus::factory()->create());

        event($event);

        expect($event->lines())->toContain('10 revisiones de catálogo nuevas');
    });

    it('reporta «1 revisión de catálogo nueva» en singular', function () {
        $term = Term::factory()->create();
        Department::factory()->create();
        createTermReviews($term, campusWithoutRooms('EAD'));
        $event = new TermOpened($term, Campus::factory()->create());

        event($event);

        expect($event->lines())->toContain('1 revisión de catálogo nueva');
    });

    it('no reporta nada si no faltaba ninguna', function () {
        $term = Term::factory()->create();
        $campus = Campus::factory()->create();
        Department::factory()->create();
        createTermReviews($term, $campus);
        $event = new TermOpened($term, $campus);

        event($event);

        expect(array_values(array_filter($event->lines(), fn (string $line): bool => str_contains($line, 'revisi'))))->toBe([]);
    });
});
