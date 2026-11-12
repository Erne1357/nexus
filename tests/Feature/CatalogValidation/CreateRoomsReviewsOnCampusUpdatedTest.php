<?php

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Actions\OpenTerm;
use App\Modules\Core\Actions\UpdateCampus;
use App\Modules\Core\Enums\Modality as CampusModality;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;

/*
| Si un campus empieza a usar salones, se crean solas las revisiones de salones que falten en los periodos abiertos
| en su modalidad (M4; por el puente `campuses.modality_id`; reglas-de-negocio.md, "Periodo y calendario"; supuesto por
| confirmar): una por departamento y periodo, sin campus (decisión 6, confirmada el 12 de noviembre de 2026). No se
| crean en periodos que no se abrieron en esa modalidad, y repetirlo no duplica nada.
*/

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());

    // En línea (sin salones en su perfil) se activa para poder abrir el periodo en ella sin crear la de salones.
    $this->online = modalityOf('ENL');
    $this->online->update(['is_active' => true]);
    $this->campus = Campus::factory()->create(['code' => 'ENL', 'modality' => CampusModality::EnLinea, 'settings' => [...CampusProfile::DEFAULTS, 'uses_rooms' => false]]);
    $this->opened = Term::factory()->create(['code' => '20271']);
    $this->notOpened = Term::factory()->create(['code' => '20272']);
    Department::factory()->create(['code' => 'D1']);
    Department::factory()->create(['code' => 'D2']);

    app(OpenTerm::class)->handle($this->opened, $this->online);
});

function roomsReviews(Term $term): int
{
    return CatalogReview::query()->where('term_id', $term->id)->where('catalog', Catalog::Rooms->value)->count();
}

it('crea las revisiones de salones de los periodos abiertos cuando el campus empieza a usar salones', function () {
    expect(roomsReviews($this->opened))->toBe(0);

    app(UpdateCampus::class)->handle($this->campus, ['settings' => [...CampusProfile::DEFAULTS, 'uses_rooms' => true]]);

    expect(roomsReviews($this->opened))->toBe(2)
        ->and(roomsReviews($this->notOpened))->toBe(0)
        ->and(CatalogReview::query()->where('catalog', Catalog::Rooms->value)->pluck('campus_id')->unique()->all())->toBe([null]);
});

it('no duplica si se vuelve a guardar el perfil, y no crea nada si el campus no usa salones', function () {
    app(UpdateCampus::class)->handle($this->campus, ['name' => 'Otro nombre']);
    expect(roomsReviews($this->opened))->toBe(0);

    app(UpdateCampus::class)->handle($this->campus, ['settings' => [...CampusProfile::DEFAULTS, 'uses_rooms' => true]]);
    app(UpdateCampus::class)->handle($this->campus, ['settings' => [...CampusProfile::DEFAULTS, 'uses_rooms' => true, 'requires_subdireccion_approval' => true]]);

    expect(roomsReviews($this->opened))->toBe(2);
});
