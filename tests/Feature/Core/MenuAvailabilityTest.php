<?php

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\MenuEntry;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;

/*
| El menú sigue al periodo activo de la credencial (MN-02 a MN-04, confirmados el 12 de noviembre de 2026): sin
| validación abierta no se ven Validar catálogos ni Avance de validación; sin carga en curso no se ven las pantallas
| de la carga. Horarios publicados, Mi carga y Oferta se ven siempre. El admin lo ve todo, con el motivo en lo que
| los demás no ven.
*/

const VALIDATION_SCREENS = ['Validar catálogos', 'Avance de validación'];

const LOAD_SCREENS = ['Propuesta de carrera', 'Bandeja del departamento', 'Horarios', 'Choques y avance', 'Acuerdos', 'Asignación docente', 'Carga por docente', 'Solicitudes de cambio', 'Cierre de la carga', 'Avance del cierre'];

beforeEach(function () {
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    $this->term = Term::factory()->current()->create(['name' => 'Ene-Jun 2027']);
});

/**
 * @return list<string>
 */
function menuLabelsOf(User $user): array
{
    test()->actingAs($user);

    return array_map(fn (NavigationItem $item): string => $item->label, app(Navigation::class)->visibleTo(app(Actor::class)));
}

function subdireccion(): User
{
    return User::factory()->withRole(Role::Subdireccion)->create();
}

it('MN-02 y MN-03: sin validación abierta, quien no es admin no ve las pantallas de la validación', function () {
    CatalogReview::factory()->for($this->term)->validated()->create();

    expect(menuLabelsOf(subdireccion()))->not->toContain(...VALIDATION_SCREENS);
});

it('MN-03: con una revisión sin validar en el periodo, las ve', function () {
    CatalogReview::factory()->for($this->term)->create();

    expect(menuLabelsOf(subdireccion()))->toContain(...VALIDATION_SCREENS);
});

it('MN-02 y MN-04: sin carga en curso no se ven las pantallas de la carga; Horarios publicados, sí', function () {
    AcademicLoad::factory()->for($this->term)->inStatus(LoadStatus::Published)->create();

    expect(menuLabelsOf(subdireccion()))->not->toContain(...LOAD_SCREENS)->toContain('Horarios publicados');
});

it('MN-04: con una carga sin publicar en el periodo, se ven', function () {
    AcademicLoad::factory()->for($this->term)->create();

    expect(menuLabelsOf(subdireccion()))->toContain(...LOAD_SCREENS, ...['Horarios publicados']);
});

it('MN-04: Mi carga y Oferta se ven siempre', function () {
    $docente = User::factory()->withRole(Role::Docente, ['campus_id' => $this->campus->id])->create();

    expect(menuLabelsOf($docente))->toBe(['Mi carga', 'Oferta']);
});

it('MN-02: decide el periodo de la credencial, no solo el actual', function () {
    $other = Term::factory()->create(['name' => 'Ago-Dic 2026']);
    AcademicLoad::factory()->for($other)->create();
    $user = subdireccion();

    expect(menuLabelsOf($user))->not->toContain('Horarios');

    session()->put(Actor::ACTIVE_TERM, $other->getKey());
    app(Actor::class)->refresh();

    expect(menuLabelsOf($user))->toContain('Horarios');
});

it('MN-02: el admin ve todo, y lo que los demás no ven lleva su motivo', function () {
    $this->actingAs(User::factory()->admin()->create());

    $reasons = collect(app(Navigation::class)->visibleByArea(app(Actor::class)))
        ->flatMap(fn (array $area): array => $area['groups'])
        ->flatMap(fn (array $group): array => $group['entries'])
        ->mapWithKeys(fn (MenuEntry $entry): array => [$entry->item->label => $entry->hiddenReason]);

    expect($reasons->only(VALIDATION_SCREENS)->unique()->values()->all())->toBe(['No hay validación abierta en Ene-Jun 2027'])
        ->and($reasons->only(LOAD_SCREENS)->unique()->values()->all())->toBe(['No hay carga en curso en Ene-Jun 2027'])
        ->and($reasons->only(LOAD_SCREENS)->keys()->all())->toBe(LOAD_SCREENS)
        ->and($reasons['Horarios publicados'])->toBeNull()
        ->and($reasons['Campus'])->toBeNull();
});
