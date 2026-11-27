<?php

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Actions\OpenCatalogValidation;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Listeners\CreateRoomsReviewsOnModalityUpdated;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Actions\OpenTerm;
use App\Modules\Core\Actions\UpdateModality;
use App\Modules\Core\Events\ModalityUpdated;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\ModalityProfile;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;

/*
| Si una modalidad empieza a usar salones, se completan las revisiones de salones que falten en los periodos abiertos en
| ella (M4) donde el admin ya abrió el catálogo de salones (CV9): una por departamento y periodo, sin campus (decisión 6,
| confirmada el 12 de noviembre de 2026). Donde los salones no se abrieron, o el periodo no se abrió en esa modalidad, no
| se crea nada, y repetirlo no duplica. El perfil es de la modalidad, no del campus (MS1, MS3).
*/

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);

    Campus::factory()->create(['code' => 'C2']);
    $this->online = Modality::factory()->create(['code' => 'M01', 'settings' => [...ModalityProfile::DEFAULTS, 'uses_rooms' => false]]);
    $this->opened = Term::factory()->create(['code' => '20271']);
    $this->notOpened = Term::factory()->create(['code' => '20272']);
    Department::factory()->create(['code' => 'D1']);

    // Presencial, con salones, deja abrir el catálogo de salones; la otra modalidad se abre en el mismo periodo.
    app(OpenTerm::class)->handle($this->opened, modalityOf('PRES'));
    app(OpenTerm::class)->handle($this->opened, $this->online);
});

function roomsReviews(Term $term): int
{
    return CatalogReview::query()->where('term_id', $term->id)->where('catalog', Catalog::Rooms->value)->count();
}

function modalityUsesRooms(Modality $modality, array $extra = []): void
{
    app(UpdateModality::class)->handle($modality, ['settings' => [...ModalityProfile::DEFAULTS, 'uses_rooms' => true, ...$extra]]);
}

it('CV9: completa las de salones del periodo donde se abrieron cuando otra modalidad empieza a usar salones', function () {
    app(OpenCatalogValidation::class)->handle($this->opened, [Catalog::Rooms], $this->admin);
    Department::factory()->create(['code' => 'D2']);

    modalityUsesRooms($this->online);

    expect(roomsReviews($this->opened))->toBe(2)
        ->and(roomsReviews($this->notOpened))->toBe(0)
        ->and(CatalogReview::query()->where('catalog', Catalog::Rooms->value)->pluck('campus_id')->unique()->all())->toBe([null]);
});

it('CV9: si el admin no abrió los salones en el periodo, el cambio de perfil no los crea', function () {
    app(OpenCatalogValidation::class)->handle($this->opened, [Catalog::Teachers], $this->admin);

    modalityUsesRooms($this->online);

    expect(roomsReviews($this->opened))->toBe(0);
});

it('no duplica si se vuelve a guardar el perfil, y no crea nada si la modalidad no usa salones', function () {
    app(OpenCatalogValidation::class)->handle($this->opened, [Catalog::Rooms], $this->admin);
    Department::factory()->create(['code' => 'D2']);

    app(UpdateModality::class)->handle($this->online, ['name' => 'Otro nombre']);
    app(UpdateModality::class)->handle($this->online, ['settings' => [...ModalityProfile::DEFAULTS, 'uses_rooms' => false, 'days' => [1, 2, 3]]]);
    expect(roomsReviews($this->opened))->toBe(1);

    modalityUsesRooms($this->online);
    modalityUsesRooms($this->online, ['requires_subdireccion_approval' => true]);

    expect(roomsReviews($this->opened))->toBe(2);
});

it('un perfil guardado inválido cuenta como sin salones: no crea nada y no falla', function () {
    app(OpenCatalogValidation::class)->handle($this->opened, [Catalog::Rooms], $this->admin);
    Department::factory()->create(['code' => 'D2']);
    $this->online->forceFill(['settings' => ['uses_rooms' => true, 'block_minutes' => 45]])->save();

    app(CreateRoomsReviewsOnModalityUpdated::class)->handle(new ModalityUpdated($this->online, ['settings']));

    expect(roomsReviews($this->opened))->toBe(1);
});
