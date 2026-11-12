<?php

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Events\TermOpened;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\ModalityProfile;
use App\Modules\Core\Models\Term;

/*
| CreateReviewsOnTermOpened: al abrir un periodo en una modalidad (M4) crea las revisiones de catálogo que falten
| (CreateTermReviews) y lo dice en el reporte de la apertura. Las generales son una vez por periodo y la de salones de
| cada departamento es una para todos los campus, si la modalidad usa salones (supuesto por confirmar, hasta que el
| admin elija qué catálogos se validan, MS15).
*/

beforeEach(function () {
    $this->presencial = modalityOf('PRES');
});

it('M4: crea las revisiones del periodo una sola vez, con la de salones si la modalidad la usa, y reporta cuántas', function () {
    Department::factory()->count(2)->create();
    $term = Term::factory()->create();
    $event = new TermOpened($term, $this->presencial);

    event($event);

    expect($event->lines())->toContain('10 revisiones de catálogo nuevas')
        ->and(CatalogReview::query()->where('catalog', Catalog::Rooms)->count())->toBe(2)
        ->and(CatalogReview::query()->whereNotNull('campus_id')->exists())->toBeFalse();
});

it('en una modalidad sin salones crea solo las generales, y la de salones llega con la primera que sí los usa', function () {
    Department::factory()->create();
    $term = Term::factory()->create();
    event(new TermOpened($term, Modality::factory()->create(['settings' => [...ModalityProfile::DEFAULTS, 'uses_rooms' => false]])));

    expect(CatalogReview::count())->toBe(4);

    $event = new TermOpened($term, $this->presencial);
    event($event);

    expect($event->lines())->toContain('1 revisión de catálogo nueva')
        ->and(CatalogReview::query()->where('catalog', Catalog::Rooms)->count())->toBe(1);
});

it('no reporta nada si no faltaba ninguna', function () {
    $term = Term::factory()->create();
    Department::factory()->create();
    event(new TermOpened($term, $this->presencial));
    $event = new TermOpened($term, $this->presencial);

    event($event);

    expect(array_values(array_filter($event->lines(), fn (string $line): bool => str_contains($line, 'revisi'))))->toBe([])
        ->and(CatalogReview::count())->toBe(5);
});
