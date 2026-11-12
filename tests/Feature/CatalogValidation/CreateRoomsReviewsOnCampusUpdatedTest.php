<?php

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Actions\OpenCatalogValidation;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Actions\OpenTerm;
use App\Modules\Core\Actions\UpdateCampus;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;

/*
| Si un campus empieza a usar salones, se completan las revisiones de salones que falten en los periodos abiertos en él
| donde el admin ya abrió el catálogo de salones (CV9): una por departamento y periodo, sin campus (decisión 6, confirmada
| el 12 de noviembre de 2026). Donde los salones no se abrieron, o el periodo no se abrió en ese campus, no se crea nada,
| y repetirlo no duplica.
*/

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);

    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'settings' => [...CampusProfile::DEFAULTS, 'uses_rooms' => false]]);
    $this->opened = Term::factory()->create(['code' => '20271']);
    $this->notOpened = Term::factory()->create(['code' => '20272']);
    Department::factory()->create(['code' => 'D1']);

    foreach ([$this->c2, $this->c1] as $campus) {
        app(OpenTerm::class)->handle($this->opened, $campus);
    }
});

function roomsReviews(Term $term): int
{
    return CatalogReview::query()->where('term_id', $term->id)->where('catalog', Catalog::Rooms->value)->count();
}

function campusUsesRooms(Campus $campus): void
{
    app(UpdateCampus::class)->handle($campus, ['settings' => [...CampusProfile::DEFAULTS, 'uses_rooms' => true]]);
}

it('CV9: completa las de salones del periodo donde se abrieron cuando otro campus empieza a usar salones', function () {
    app(OpenCatalogValidation::class)->handle($this->opened, [Catalog::Rooms], $this->admin);
    Department::factory()->create(['code' => 'D2']);

    campusUsesRooms($this->c1);

    expect(roomsReviews($this->opened))->toBe(2)
        ->and(roomsReviews($this->notOpened))->toBe(0)
        ->and(CatalogReview::query()->where('catalog', Catalog::Rooms->value)->pluck('campus_id')->unique()->all())->toBe([null]);
});

it('CV9: si el admin no abrió los salones en el periodo, el cambio de perfil no los crea', function () {
    app(OpenCatalogValidation::class)->handle($this->opened, [Catalog::Teachers], $this->admin);

    campusUsesRooms($this->c1);

    expect(roomsReviews($this->opened))->toBe(0);
});

it('no duplica si se vuelve a guardar el perfil, y no crea nada si el campus no usa salones', function () {
    app(OpenCatalogValidation::class)->handle($this->opened, [Catalog::Rooms], $this->admin);
    Department::factory()->create(['code' => 'D2']);

    app(UpdateCampus::class)->handle($this->c1, ['name' => 'Otro nombre']);
    expect(roomsReviews($this->opened))->toBe(1);

    campusUsesRooms($this->c1);
    app(UpdateCampus::class)->handle($this->c1, ['settings' => [...CampusProfile::DEFAULTS, 'uses_rooms' => true, 'requires_subdireccion_approval' => true]]);

    expect(roomsReviews($this->opened))->toBe(2);
});
