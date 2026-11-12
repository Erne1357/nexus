<?php

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Queries\LoadProgressQuery;
use App\Modules\Core\Models\Term;

/*
| MN-04 (confirmado el 12 de noviembre de 2026): hay carga en curso en un periodo si tiene cargas y al menos una
| no está publicada; una en re-análisis cuenta como no publicada (supuesto por confirmar). Decide si el menú
| muestra las pantallas de la carga a quien no es admin.
*/

beforeEach(function () {
    $this->term = Term::factory()->create(['name' => 'Ene-Jun 2027']);
});

it('MN-04: con una carga sin publicar, hay carga en curso', function (LoadStatus $status) {
    AcademicLoad::factory()->for($this->term)->inStatus(LoadStatus::Published)->create();
    AcademicLoad::factory()->for($this->term)->inStatus($status)->create();

    expect(app(LoadProgressQuery::class)->inProgress($this->term))->toBeTrue()
        ->and(app(LoadProgressQuery::class)->whyUnavailable($this->term))->toBeNull();
})->with([
    'borrador' => [LoadStatus::Draft],
    'en asignación' => [LoadStatus::Staffing],
    'cerrada' => [LoadStatus::Closed],
    'en re-análisis' => [LoadStatus::Reanalysis],
]);

it('MN-04: con todas publicadas, o sin cargas, no hay carga en curso', function (bool $withLoads) {
    if ($withLoads) {
        AcademicLoad::factory()->for($this->term)->inStatus(LoadStatus::Published)->count(2)->create();
    }

    expect(app(LoadProgressQuery::class)->inProgress($this->term))->toBeFalse()
        ->and(app(LoadProgressQuery::class)->whyUnavailable($this->term))->toBe('No hay carga en curso en Ene-Jun 2027');
})->with(['todas publicadas' => [true], 'sin cargas' => [false]]);

it('MN-04: solo cuentan las cargas del periodo', function () {
    AcademicLoad::factory()->create();

    expect(app(LoadProgressQuery::class)->inProgress($this->term))->toBeFalse();
});

it('MN-04: sin periodo elegido no está disponible', function () {
    expect(app(LoadProgressQuery::class)->whyUnavailable(null))->toBe('No hay un periodo elegido');
});
