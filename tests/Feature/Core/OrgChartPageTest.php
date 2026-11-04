<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Database\Seeders\OrgChartSeeder;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Core\Queries\ScopedContext;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| «Organigrama» (`/org-chart`, core::pages.org-chart, maqueta B): el diagrama de cajas pintado en el servidor y, a
| un lado, el panel de la unidad elegida (core::components.org-unit-panel), que da de alta, renombra, mueve,
| desactiva y activa con las Actions de Core y avisa al diagrama. Solo el admin con ese perfil activo la ve
| (OrgUnitPolicy); cada método vuelve a autorizar, aunque llegue con una página que abrió el admin.
*/

beforeEach(function () {
    $this->travelTo('2026-11-03 10:00:00');
    $this->seed(OrgChartSeeder::class);

    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->systems = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->isc = Career::factory()->create(['code' => 'ISC', 'department_id' => $this->systems->id]);
    CareerCampus::factory()->create(['career_id' => $this->isc->id, 'campus_id' => $this->c2->id]);

    $this->admin = User::factory()->admin()->create(['name' => 'Ada Admin']);
    $this->coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->isc->id])->create();

    $this->root = OrgUnit::where('code', 'DIRECCION')->sole();
    $this->academic = OrgUnit::where('code', 'SUB-ACADEMICA')->sole();
    $this->planning = OrgUnit::where('code', 'SUB-PLANEACION')->sole();
    $this->administrative = OrgUnit::where('code', 'SUB-ADMINISTRATIVA')->sole();
    $this->services = OrgUnit::where('code', 'SERVICIOS-ESCOLARES')->sole();
    $this->activityBase = (int) Activity::max('id');
});

function orgUnitPanel(User $user): Testable
{
    return Livewire::actingAs($user)->test('core::components.org-unit-panel');
}

function seesOrgChartMenu(User $user): bool
{
    test()->actingAs($user);

    return collect(app(Navigation::class)->visibleTo(app(ScopedContext::class)))
        ->contains(fn (NavigationItem $item): bool => $item->label === 'Organigrama');
}

it('pinta el diagrama con la Dirección arriba y una caja por unidad', function () {
    OrgUnit::factory()->inactive()->create(['parent_id' => $this->planning->id, 'code' => 'BECAS', 'name' => 'Oficina de Becas', 'kind' => OrgUnitKind::Oficina]);

    $response = $this->actingAs($this->admin)
        ->get(route('core.org-chart'))
        ->assertOk()
        ->assertSee('<title>Organigrama · Nexus</title>', false)
        ->assertSee('La estructura del Instituto de un vistazo. Toca una unidad para ver su titular, sus puestos y sus acciones. Los departamentos académicos (con candado) se editan en Académico.')
        ->assertSee('Mostrar inactivas (1)')
        ->assertSee('1 departamento académico sin jefe')
        ->assertSee('Nueva unidad');

    $boxes = array_map(
        fn ($box): string => substr($box->getAttribute('data-testid'), strlen('org-unit-')),
        iterator_to_array(domOf($response)->querySelectorAll('button[data-testid^="org-unit-"]')),
    );

    expect($boxes[0])->toBe('DIRECCION')
        ->and($boxes)->toEqualCanonicalizing(OrgUnit::query()->where('is_active', true)->pluck('code')->all())
        ->and(textOf(domOf($response)->querySelector('[data-testid="org-unit-SC"]')))->toContain('Departamento académico')->toContain('Sistemas y Computación')->toContain('Sin jefe vigente')
        ->and(textOf(domOf($response)->querySelector('[data-testid="org-unit-DIRECCION"]')))->toContain('Sin titular en Nexus')
        ->and(textOf(domOf($response)->querySelector('[data-testid="org-legend"]')))->toBe('Se edita en Académico · n personas en la unidad y en las que cuelgan de ella');
});

it('el panel muestra la unidad elegida', function () {
    $alejandro = User::factory()->withRole(Role::Subdireccion, ['valid_from' => '2025-09-01'])->create(['name' => 'Alejandro Rentería Cota']);

    $panel = orgUnitPanel($this->admin)
        ->assertSet('unitId', $this->root->id)
        ->assertSee('La Dirección no tiene un puesto en Nexus.')
        ->assertDontSeeHtml('data-testid="unit-path"');

    $panel->dispatch('org-unit-picked', id: $this->planning->id)
        ->assertSet('unitId', $this->planning->id)
        ->assertSee('Subdirección de Planeación y Vinculación')
        ->assertSee('SUB-PLANEACION')
        ->assertSee('Dirección › Subdirección de Planeación y Vinculación')
        ->assertSee('Esta unidad todavía no tiene un puesto de titular en Nexus.')
        ->assertSee('con adscripción o puesto vigente en la unidad y en las que cuelgan de ella')
        ->assertSee('Departamento de Servicios Escolares');

    $panel->dispatch('org-unit-picked', id: $this->academic->id)
        ->assertSee('Alejandro Rentería Cota')
        ->assertSeeText('Subdirección Académica · desde 01/09/2025')
        ->assertSee('Ver en Personas y puestos')
        ->assertSee(route('core.people.show', $alejandro->id))
        ->assertSee('Puestos vigentes aquí');
});

it('«Nueva unidad» cuelga la unidad de la del panel si admite hijas; si no, de la Dirección', function () {
    $panel = orgUnitPanel($this->admin);

    $panel->dispatch('org-unit-create')->assertSet('parent', $this->root->id)->assertSet('showForm', true);
    $panel->dispatch('org-unit-picked', id: $this->planning->id)->dispatch('org-unit-create')->assertSet('parent', $this->planning->id);
    $panel->dispatch('org-unit-picked', id: $this->systems->id)->dispatch('org-unit-create')->assertSet('parent', $this->root->id);
});

it('da de alta, renombra, mueve, desactiva y activa desde el panel, y avisa al diagrama', function () {
    $panel = orgUnitPanel($this->admin)->dispatch('org-unit-picked', id: $this->planning->id);

    $panel->call('openCreate', $this->planning->id)
        ->assertSet('parent', $this->planning->id)
        ->assertSee('Nueva unidad')
        ->set('name', 'Oficina de Becas')
        ->set('code', 'BECAS')
        ->set('kind', 'oficina')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false);

    $grants = OrgUnit::where('code', 'BECAS')->sole();

    expect($grants)->parent_id->toBe($this->planning->id)->kind->toBe(OrgUnitKind::Oficina)->name->toBe('Oficina de Becas')
        ->and(lastToastOf($panel))->title->toBe('Unidad creada')->description->toBe('BECAS · Oficina de Becas');

    $panel->assertDispatched('org-chart-changed', id: $grants->id)->assertSet('unitId', $grants->id);

    $panel->call('openEdit')
        ->assertSet('name', 'Oficina de Becas')
        ->assertSee('Cambia el nombre, la clave o el tipo; el lugar se cambia con «Mover».')
        ->set('name', 'Oficina de Becas y Apoyos')
        ->call('save')
        ->assertHasNoErrors();

    expect($grants->fresh()->name)->toBe('Oficina de Becas y Apoyos')
        ->and(lastToastOf($panel))->title->toBe('Cambio guardado');

    $panel->call('openMove')
        ->assertSet('parent', $this->planning->id)
        ->assertSee('Al moverla, sus unidades hijas se van con ella. No puede depender de sí misma ni de una de sus hijas.')
        ->set('parent', $this->administrative->id)
        ->call('move')
        ->assertHasNoErrors();

    expect($grants->fresh()->parent_id)->toBe($this->administrative->id)
        ->and(lastToastOf($panel))->title->toBe('Unidad movida')->description->toBe('Oficina de Becas y Apoyos ahora depende de Subdirección de Servicios Administrativos');

    $panel->call('setActive', false)->assertHasNoErrors();

    expect($grants->fresh()->is_active)->toBeFalse()
        ->and(lastToastOf($panel))->title->toBe('Unidad desactivada');

    $panel->call('setActive', true)->assertHasNoErrors();

    expect($grants->fresh()->is_active)->toBeTrue()
        ->and(lastToastOf($panel))->title->toBe('Unidad activada')
        ->and(activitySince()->count())->toBe(5);

    Livewire::actingAs($this->admin)->test('core::pages.org-chart')
        ->dispatch('org-chart-changed', id: $grants->id)
        ->assertSee('data-testid="org-unit-BECAS"', false);
});

it('mover a la misma madre o guardar sin cambios no avisa al diagrama', function () {
    $panel = orgUnitPanel($this->admin)->dispatch('org-unit-picked', id: $this->services->id);

    $panel->call('openMove')->assertSet('parent', $this->planning->id)->call('move')
        ->assertHasNoErrors()
        ->assertNotDispatched('org-chart-changed')
        ->assertSet('showForm', false);

    expect(lastToastOf($panel))->title->toBe('Sin cambios')->description->toBe('Departamento de Servicios Escolares ya depende de Subdirección de Planeación y Vinculación');

    $panel->call('openEdit')->call('save')
        ->assertHasNoErrors()
        ->assertNotDispatched('org-chart-changed')
        ->assertSet('showForm', false);

    expect(lastToastOf($panel))->title->toBe('Sin cambios')
        ->and(activitySince()->count())->toBe(0);
});

it('lo bloqueado se queda deshabilitado con su motivo escrito', function () {
    $panel = orgUnitPanel($this->admin);
    $dom = domOf($panel);

    expect($dom->querySelector('[data-testid="unit-rename"]')->hasAttribute('disabled'))->toBeFalse()
        ->and($dom->querySelector('[data-testid="unit-add-child"]')->hasAttribute('disabled'))->toBeFalse()
        ->and($dom->querySelector('[data-testid="unit-move"]')->hasAttribute('disabled'))->toBeTrue()
        ->and($dom->querySelector('[data-testid="unit-deactivate"]')->hasAttribute('disabled'))->toBeTrue()
        ->and(textOf($dom->querySelector('[data-testid="unit-blockers"]')))->toContain('La Dirección es la raíz: no se mueve.')->toContain('La Dirección es la raíz: no se desactiva.');

    $panel->dispatch('org-unit-picked', id: $this->academic->id);
    $dom = domOf($panel);

    expect($dom->querySelector('[data-testid="unit-deactivate"]')->hasAttribute('disabled'))->toBeTrue()
        ->and($dom->querySelector('[data-testid="unit-move"]')->hasAttribute('disabled'))->toBeFalse()
        ->and(textOf($dom->querySelector('[data-testid="unit-blockers"]')))->toContain('Tiene 5 unidades hijas activas; muévelas o desactívalas primero.');

    $panel->dispatch('org-unit-picked', id: $this->systems->id);
    $dom = domOf($panel);

    expect($dom->querySelector('[data-testid="unit-rename"]')->hasAttribute('disabled'))->toBeTrue()
        ->and($dom->querySelector('[data-testid="unit-move"]')->hasAttribute('disabled'))->toBeTrue()
        ->and($dom->querySelector('[data-testid="unit-deactivate"]')->hasAttribute('disabled'))->toBeTrue()
        ->and($dom->querySelector('[data-testid="unit-add-child"]'))->toBeNull()
        ->and(textOf($dom->querySelector('[data-testid="unit-blockers"]')))->toBe('Es un departamento académico: su nombre, su clave y su estado se editan en Académico › Departamentos.');

    $panel->call('openEdit')->assertHasErrors(['unit'])->assertSet('showForm', false);
    $panel->call('openMove')->assertHasErrors(['unit'])->assertSet('showForm', false);
    $panel->call('setActive', false)->assertHasErrors(['unit']);

    $panel->dispatch('org-unit-picked', id: $this->academic->id)->call('openEdit')->assertSet('showForm', true);

    expect(domOf($panel)->querySelector('select[name="kind"]')->hasAttribute('disabled'))->toBeTrue()
        ->and(textOf(domOf($panel)->querySelector('[data-testid="unit-kind-fixed"]')))->toBe('Nexus usa Subdirección Académica como subdirección; no cambia de tipo.');

    expect($this->systems->fresh()->is_active)->toBeTrue()
        ->and(activitySince()->count())->toBe(0);
});

it('muestra en español los errores de las Actions junto al campo', function () {
    $panel = orgUnitPanel($this->admin);

    $panel->call('openCreate', $this->planning->id)
        ->set('name', '')
        ->set('code', 'SUB-ADMINISTRATIVA')
        ->set('kind', 'oficina')
        ->call('save')
        ->assertHasErrors(['name'])
        ->assertSee('Escribe el nombre.');

    $panel->set('name', 'Otra oficina')
        ->call('save')
        ->assertHasErrors(['code'])
        ->assertSee('Ya hay una unidad con la clave SUB-ADMINISTRATIVA.')
        ->assertSet('showForm', true);

    $panel->set('code', 'OTRA')
        ->set('kind', 'departamento_academico')
        ->call('save')
        ->assertHasErrors(['kind'])
        ->assertSee('Los departamentos académicos se dan de alta en Académico.');

    $panel->dispatch('org-unit-picked', id: $this->planning->id)
        ->call('openMove')
        ->set('parent', $this->services->id)
        ->call('move')
        ->assertHasErrors(['parent'])
        ->assertSee('Subdirección de Planeación y Vinculación no puede depender de sí misma ni de una de sus hijas.');

    expect(OrgUnit::where('code', 'OTRA')->exists())->toBeFalse()
        ->and($this->planning->fresh()->parent_id)->toBe($this->root->id)
        ->and(activitySince()->count())->toBe(0);
});

it('Mostrar inactivas enseña las inactivas', function () {
    OrgUnit::factory()->inactive()->create(['parent_id' => $this->planning->id, 'code' => 'BECAS', 'name' => 'Oficina de Becas', 'kind' => OrgUnitKind::Oficina]);

    Livewire::actingAs($this->admin)->test('core::pages.org-chart')
        ->assertDontSee('data-testid="org-unit-BECAS"', false)
        ->set('showInactive', true)
        ->assertSee('data-testid="org-unit-BECAS"', false)
        ->assertSee('Inactiva');
});

it('el menú muestra «Organigrama» solo al admin', function () {
    $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();
    $items = collect(app(Navigation::class)->items())->filter(fn (NavigationItem $item): bool => $item->area === NavigationArea::Academico)->pluck('label')->values();
    $item = collect(app(Navigation::class)->items())->firstWhere('label', 'Organigrama');

    expect(seesOrgChartMenu($this->admin))->toBeTrue()
        ->and(seesOrgChartMenu($this->coordinator))->toBeFalse()
        ->and(seesOrgChartMenu($subdireccion))->toBeFalse()
        ->and($item)->route->toBe('core.org-chart')->icon->toBe('o-rectangle-group')
        ->and($items->search('Organigrama'))->toBe($items->search('Personas y puestos') - 1)
        ->and(route('core.org-chart', absolute: false))->toBe('/org-chart');
});

describe('otros perfiles', function () {
    it('un coordinador recibe 403 en la ruta', function () {
        $this->actingAs($this->coordinator)->get(route('core.org-chart'))->assertForbidden();
    });

    it('la misma persona con Coordinación activa recibe 403', function () {
        $both = User::factory()->admin()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->isc->id])->create();

        actingAsRole($both, Role::Admin)->get(route('core.org-chart'))->assertOk();
        actingAsRole($both, Role::Coordinador)->get(route('core.org-chart'))->assertForbidden();
    });

    it('el diagrama rechaza a quien ya no es admin, sobre una página que abrió el admin', function () {
        $page = Livewire::actingAs($this->admin)->test('core::pages.org-chart');

        $this->actingAs($this->coordinator);

        $page->set('showInactive', true)->assertForbidden();
    });

    it('cada método del panel rechaza a quien ya no es admin', function (string $method) {
        $panel = orgUnitPanel($this->admin)->dispatch('org-unit-picked', id: $this->services->id);

        match ($method) {
            'save' => $panel->call('openCreate', $this->planning->id)->set('name', 'Oficina de Becas')->set('code', 'BECAS')->set('kind', 'oficina'),
            'move' => $panel->call('openMove')->set('parent', $this->administrative->id),
            default => null,
        };

        $this->actingAs($this->coordinator);

        match ($method) {
            'pick' => $panel->dispatch('org-unit-picked', id: $this->planning->id)->assertForbidden(),
            'openCreate' => $panel->call('openCreate', $this->planning->id)->assertForbidden(),
            'setActive' => $panel->call('setActive', false)->assertForbidden(),
            default => $panel->call($method)->assertForbidden(),
        };

        expect(OrgUnit::where('code', 'BECAS')->exists())->toBeFalse()
            ->and($this->services->fresh())->parent_id->toBe($this->planning->id)->is_active->toBeTrue()
            ->and(activitySince()->count())->toBe(0);
    })->with(['pick', 'openCreate', 'openEdit', 'openMove', 'save', 'move', 'setActive']);
});
