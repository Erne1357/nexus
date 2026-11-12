<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Database\Seeders\OrgChartSeeder;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
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
    $this->linkage = OrgUnit::where('code', 'VINCULACION')->sole();
    $this->activityBase = (int) Activity::max('id');
});

function orgUnitPanel(User $user): Testable
{
    return Livewire::actingAs($user)->test('core::components.org-unit-panel');
}

function seesOrgChartMenu(User $user): bool
{
    test()->actingAs($user);

    return collect(app(Navigation::class)->visibleTo(app(Actor::class)))
        ->contains(fn (NavigationItem $item): bool => $item->label === 'Organigrama');
}

it('pinta el diagrama con la Dirección arriba y una caja por unidad', function () {
    OrgUnit::factory()->inactive()->create(['parent_id' => $this->planning->id, 'code' => 'BECAS', 'name' => 'Oficina de Becas', 'kind' => OrgUnitKind::Oficina]);

    $response = $this->actingAs($this->admin)
        ->get(route('core.org-chart'))
        ->assertOk()
        ->assertSee('<title>Organigrama · Nexus</title>', false)
        ->assertSee('La estructura del Instituto de un vistazo. Toca una unidad para ver su titular, sus puestos y sus acciones. Los departamentos académicos (con candado) se editan en Administración › Departamentos.')
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
        ->and(textOf(domOf($response)->querySelector('[data-testid="org-legend"]')))->toBe('Se edita en Administración › Departamentos · n personas en la unidad y en las que cuelgan de ella');
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
        ->assertSee('Departamento de Gestión Tecnológica y Vinculación');

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

    $panel->call('openMove')->assertSet('parent', $this->academic->id)->call('move')
        ->assertHasNoErrors()
        ->assertNotDispatched('org-chart-changed')
        ->assertSet('showForm', false);

    expect(lastToastOf($panel))->title->toBe('Sin cambios')->description->toBe('Departamento de Servicios Escolares ya depende de Subdirección Académica');

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
        ->and($dom->querySelector('[data-testid="unit-move"]')->hasAttribute('disabled'))->toBeTrue()
        ->and(textOf($dom->querySelector('[data-testid="unit-blockers"]')))->toContain('Tiene 6 unidades hijas activas; muévelas o desactívalas primero.')
        ->toContain('Mover: Nexus usa la Subdirección Académica: no se mueve.');

    $panel->dispatch('org-unit-picked', id: $this->systems->id);
    $dom = domOf($panel);

    expect($dom->querySelector('[data-testid="unit-rename"]')->hasAttribute('disabled'))->toBeTrue()
        ->and($dom->querySelector('[data-testid="unit-move"]')->hasAttribute('disabled'))->toBeTrue()
        ->and($dom->querySelector('[data-testid="unit-deactivate"]')->hasAttribute('disabled'))->toBeTrue()
        ->and($dom->querySelector('[data-testid="unit-add-child"]'))->toBeNull()
        ->and(textOf($dom->querySelector('[data-testid="unit-blockers"]')))->toBe('Es un departamento académico: su nombre, su clave y su estado se editan en Administración › Departamentos.');

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
        ->assertSee('Los departamentos académicos se dan de alta en Administración › Departamentos.');

    $panel->dispatch('org-unit-picked', id: $this->planning->id)
        ->call('openMove')
        ->set('parent', $this->linkage->id)
        ->call('move')
        ->assertHasErrors(['parent'])
        ->assertSee('Subdirección de Planeación y Vinculación no puede depender de sí misma ni de una de sus hijas.');

    expect(OrgUnit::where('code', 'OTRA')->exists())->toBeFalse()
        ->and($this->planning->fresh()->parent_id)->toBe($this->root->id)
        ->and(activitySince()->count())->toBe(0);
});

/**
 * Abre «Nombrar» sobre la unidad, busca a la persona y la elige.
 */
function appointPanelOn(OrgUnit|Department $unit, string $search, string $username): Testable
{
    return orgUnitPanel(test()->admin)
        ->dispatch('org-unit-picked', id: $unit->id)
        ->call('openAppoint')
        ->set('findPerson', $search)
        ->call('choosePerson', User::where('username', $username)->sole()->id);
}

it('P1: nombra al jefe de un departamento desde el panel, con la unidad fija, y avisa al diagrama', function () {
    $rosa = User::factory()->create(['name' => 'Rosa Pérez', 'username' => 'rosa']);

    $panel = orgUnitPanel($this->admin)
        ->dispatch('org-unit-picked', id: $this->systems->id)
        ->call('openAppoint')
        ->assertSet('showAppoint', true)
        ->assertSet('role', 'jefe_departamento')
        ->assertSet('unit', $this->systems->id)
        ->assertSee('Nombrar a alguien en Sistemas y Computación')
        ->set('findPerson', 'Rosa')
        ->assertSeeHtml('data-testid="appoint-pick-rosa"')
        ->call('choosePerson', $rosa->id)
        ->assertSet('appointUserId', $rosa->id)
        ->assertSeeHtml('data-testid="appoint-chosen"')
        ->call('appoint')
        ->assertHasNoErrors()
        ->assertSet('showAppoint', false)
        ->assertDispatched('org-chart-changed', id: $this->systems->id);

    $assignment = $rosa->roleAssignments()->sole();

    expect($assignment->role)->toBe(Role::JefeDepartamento)
        ->and($assignment->org_unit_id)->toBe($this->systems->id)
        ->and(lastToastOf($panel))->title->toBe('Nombramiento creado');

    $panel->assertSee('Rosa Pérez');
});

it('P1: nombra jefe de proyecto docente pidiendo el campus', function () {
    $panel = appointPanelOn($this->systems, 'Rosa', User::factory()->create(['name' => 'Rosa Pérez', 'username' => 'rosa'])->username)
        ->set('role', 'jefe_proyecto_docente')
        ->assertSet('unit', $this->systems->id)
        ->call('appoint')
        ->assertHasErrors(['campus' => 'Elige el campus.']);

    $panel->set('campus', $this->c2->id)->call('appoint')->assertHasNoErrors();

    $assignment = User::where('username', 'rosa')->sole()->roleAssignments()->sole();

    expect($assignment->role)->toBe(Role::JefeProyectoDocente)
        ->and($assignment->campus_id)->toBe($this->c2->id)
        ->and($assignment->org_unit_id)->toBe($this->systems->id);
});

it('la Subdirección se nombra sobre Subdirección Académica, con la unidad fija', function () {
    User::factory()->create(['name' => 'Rosa Pérez', 'username' => 'rosa']);

    appointPanelOn($this->academic, 'Rosa', 'rosa')->assertSet('role', 'subdireccion')->call('appoint')->assertHasNoErrors();

    expect(User::where('username', 'rosa')->sole()->roleAssignments()->sole())->role->toBe(Role::Subdireccion)->org_unit_id->toBe($this->academic->id);
});

it('el puesto exclusivo ocupado da su error en el formulario, no en las acciones de la unidad', function () {
    User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => $this->systems->id, 'valid_from' => '2026-01-01'])->create(['name' => 'Ana Jefa']);
    User::factory()->create(['name' => 'Rosa Pérez', 'username' => 'rosa']);

    $panel = appointPanelOn($this->systems, 'Rosa', 'rosa')->call('appoint')->assertHasErrors(['unit'])->assertSet('showAppoint', true);
    $dom = domOf($panel);

    expect(textOf($dom->querySelector('[data-testid="field-unit"]')))->toContain('Sistemas y Computación ya tiene jefe en esas fechas: Ana Jefa')
        ->and($dom->querySelector('[data-testid="unit-error"]'))->toBeNull()
        ->and(User::where('username', 'rosa')->sole()->roleAssignments()->count())->toBe(0);
});

it('con el formulario abierto y el puesto ocupado, el error de la unidad sale una sola vez en el HTML', function () {
    User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => $this->systems->id, 'valid_from' => '2026-01-01'])->create(['name' => 'Ana Jefa']);
    User::factory()->create(['name' => 'Rosa Pérez', 'username' => 'rosa']);

    $panel = appointPanelOn($this->systems, 'Rosa', 'rosa')->call('appoint')->assertHasErrors(['unit']);

    expect(substr_count($panel->html(), 'ya tiene jefe en esas fechas'))->toBe(1);
});

it('llamar a «Nombrar» sobre una unidad que quedó inactiva se rechaza y el error sale bajo la unidad fija', function () {
    User::factory()->create(['name' => 'Rosa Pérez', 'username' => 'rosa']);
    $panel = appointPanelOn($this->systems, 'Rosa', 'rosa');

    $this->systems->update(['is_active' => false]);

    $panel->call('appoint')->assertHasErrors(['unit' => 'Sistemas y Computación está inactiva.'])->assertSet('showAppoint', true);

    expect(textOf(domOf($panel)->querySelector('[data-testid="field-unit"]')))->toContain('Sistemas y Computación está inactiva.')
        ->and(domOf($panel)->querySelector('[data-testid="unit-error"]'))->toBeNull()
        ->and(User::where('username', 'rosa')->sole()->roleAssignments()->count())->toBe(0);
});

it('forzar un puesto que no va sobre la unidad, o mandar otra unidad, no escribe en otro lado', function () {
    User::factory()->create(['name' => 'Rosa Pérez', 'username' => 'rosa']);
    $panel = appointPanelOn($this->systems, 'Rosa', 'rosa');

    $panel->set('role', 'coordinador')->call('appoint')->assertHasErrors(['role' => 'Elige un puesto de la lista.']);
    $panel->set('role', 'admin')->call('appoint')->assertHasErrors(['role']);

    expect(RoleAssignment::query()->where('user_id', User::where('username', 'rosa')->value('id'))->exists())->toBeFalse();

    $panel->set('role', 'jefe_departamento')->set('unit', $this->services->id)->call('appoint')->assertHasNoErrors();

    expect(User::where('username', 'rosa')->sole()->roleAssignments()->sole()->org_unit_id)->toBe($this->systems->id);
});

it('sin persona elegida pide elegirla', function () {
    $panel = orgUnitPanel($this->admin)->dispatch('org-unit-picked', id: $this->systems->id)->call('openAppoint')->call('appoint');

    $panel->assertHasErrors(['appointUserId' => 'Elige a la persona.']);

    expect(domOf($panel)->querySelector('[data-testid="appoint-person-error"]'))->not->toBeNull()
        ->and(RoleAssignment::query()->where('role', Role::JefeDepartamento)->count())->toBe(0);
});

it('«Nombrar» queda deshabilitado con su motivo en una unidad sin puestos y en una inactiva', function () {
    $panel = orgUnitPanel($this->admin);
    $dom = domOf($panel);

    expect($dom->querySelector('[data-testid="unit-appoint"]')->hasAttribute('disabled'))->toBeTrue()
        ->and(textOf($dom->querySelector('[data-testid="unit-appoint-blocker"]')))->toBe('Ningún puesto se nombra sobre esta unidad.')
        ->and(textOf($dom->querySelector('[data-testid="unit-assignments-empty"]')))->toBe('Nadie tiene un puesto vigente aquí.');

    $panel->call('openAppoint')->assertSet('showAppoint', false)->assertHasErrors(['unit' => 'Ningún puesto se nombra sobre esta unidad.']);

    expect(domOf($panel)->querySelector('[data-testid="unit-error"]'))->not->toBeNull();

    $this->systems->update(['is_active' => false]);
    $panel->dispatch('org-unit-picked', id: $this->systems->id);
    $dom = domOf($panel);

    expect($dom->querySelector('[data-testid="unit-appoint"]')->hasAttribute('disabled'))->toBeTrue()
        ->and(textOf($dom->querySelector('[data-testid="unit-appoint-blocker"]')))->toBe('Sistemas y Computación está inactiva.');

    $panel->call('openAppoint')->assertSet('showAppoint', false)->assertHasErrors(['unit' => 'Sistemas y Computación está inactiva.']);
});

it('termina un puesto desde el panel con la fecha elegida y quita uno programado', function () {
    $current = User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => $this->systems->id, 'valid_from' => '2026-01-01'])->create();
    $scheduled = User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->c2->id, 'org_unit_id' => $this->systems->id, 'valid_from' => '2027-01-15'])->create();
    $panel = orgUnitPanel($this->admin)->dispatch('org-unit-picked', id: $this->systems->id);

    expect(domOf($panel)->querySelectorAll('[data-testid^="end-"]'))->toHaveCount(2);

    $panel->call('confirmEnd', $current->roleAssignments()->sole()->id)
        ->assertSet('endingId', $current->roleAssignments()->sole()->id)
        ->assertSet('lastDay', '2026-11-03')
        ->assertSeeHtml('data-testid="end-box"')
        ->set('lastDay', '2026-12-15')
        ->call('end')
        ->assertHasNoErrors()
        ->assertSet('endingId', null)
        ->assertDispatched('org-chart-changed', id: $this->systems->id);

    expect($current->roleAssignments()->sole()->valid_to->toDateString())->toBe('2026-12-15')
        ->and(lastToastOf($panel))->title->toBe('Nombramiento terminado')->description->toBe('Último día: 15/12/2026');

    $panel->call('confirmEnd', $scheduled->roleAssignments()->sole()->id)
        ->assertSee('Todavía no empieza')
        ->set('lastDay', '2026-12-31')
        ->call('end');

    expect($scheduled->roleAssignments()->count())->toBe(0)
        ->and(lastToastOf($panel))->title->toBe('Nombramiento quitado');
});

it('quitar desde el panel un nombramiento que empieza hoy dice que empezaba hoy', function () {
    $head = User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->c2->id, 'org_unit_id' => $this->systems->id, 'valid_from' => '2026-11-03'])->create();
    $assignment = $head->roleAssignments()->sole();

    $panel = orgUnitPanel($this->admin)->dispatch('org-unit-picked', id: $this->systems->id)
        ->call('confirmEnd', $assignment->id)
        ->set('lastDay', '2026-11-02')
        ->call('end')
        ->assertHasNoErrors();

    expect(lastToastOf($panel))->title->toBe('Nombramiento quitado')->description->toBe('Empezaba hoy.')
        ->and(RoleAssignment::find($assignment->id))->toBeNull();
});

it('elegir otra caja cierra el formulario y el recuadro de terminar', function () {
    $head = User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => $this->systems->id, 'valid_from' => '2026-01-01'])->create();
    $panel = orgUnitPanel($this->admin)->dispatch('org-unit-picked', id: $this->systems->id);

    $panel->call('openAppoint')->assertSet('showAppoint', true)
        ->dispatch('org-unit-picked', id: $this->planning->id)
        ->assertSet('showAppoint', false)
        ->assertSet('unitId', $this->planning->id);

    $panel->dispatch('org-unit-picked', id: $this->systems->id)
        ->call('confirmEnd', $head->roleAssignments()->sole()->id)->assertSet('endingId', $head->roleAssignments()->sole()->id)
        ->dispatch('org-unit-picked', id: $this->planning->id)
        ->assertSet('endingId', null);
});

it('terminar un nombramiento de otra unidad, o uno ya terminado, da 404 y no escribe', function () {
    $foreign = User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => $this->systems->id, 'valid_from' => '2026-01-01'])->create();
    $over = User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->c2->id, 'org_unit_id' => $this->academic->id, 'valid_from' => '2020-01-01', 'valid_to' => '2020-12-31'])->create();

    orgUnitPanel($this->admin)->dispatch('org-unit-picked', id: $this->services->id)
        ->call('confirmEnd', $foreign->roleAssignments()->sole()->id)->assertNotFound()->assertSet('endingId', null);

    expect($foreign->roleAssignments()->sole()->valid_to)->toBeNull();

    orgUnitPanel($this->admin)->dispatch('org-unit-picked', id: $this->academic->id)
        ->call('confirmEnd', $over->roleAssignments()->sole()->id)->assertNotFound();
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
    $items = collect(app(Navigation::class)->items())->filter(fn (NavigationItem $item): bool => $item->area === NavigationArea::Administracion)->pluck('label')->values();
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
        $this->head = User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => $this->systems->id, 'valid_from' => '2026-01-01'])->create();
        $panel = orgUnitPanel($this->admin)->dispatch('org-unit-picked', id: $method === 'openAppoint' || str_starts_with($method, 'choose') || in_array($method, ['appoint', 'confirmEnd', 'cancelEnd', 'end'], true) ? $this->systems->id : $this->services->id);

        match ($method) {
            'choosePerson' => $panel->call('openAppoint'),
            'appoint' => $panel->call('openAppoint')->call('choosePerson', $this->coordinator->id),
            'end' => $panel->call('confirmEnd', $this->head->roleAssignments()->sole()->id),
            'save' => $panel->call('openCreate', $this->planning->id)->set('name', 'Oficina de Becas')->set('code', 'BECAS')->set('kind', 'oficina'),
            'move' => $panel->call('openMove')->set('parent', $this->administrative->id),
            default => null,
        };

        $this->actingAs($this->coordinator);

        match ($method) {
            'pick' => $panel->dispatch('org-unit-picked', id: $this->planning->id)->assertForbidden(),
            'openCreate' => $panel->call('openCreate', $this->planning->id)->assertForbidden(),
            'setActive' => $panel->call('setActive', false)->assertForbidden(),
            'choosePerson' => $panel->call('choosePerson', $this->coordinator->id)->assertForbidden(),
            'confirmEnd' => $panel->call('confirmEnd', $this->head->roleAssignments()->sole()->id)->assertForbidden(),
            default => $panel->call($method)->assertForbidden(),
        };

        expect(OrgUnit::where('code', 'BECAS')->exists())->toBeFalse()
            ->and($this->head->roleAssignments()->sole()->valid_to)->toBeNull()
            ->and($this->coordinator->roleAssignments()->count())->toBe(1)
            ->and($this->services->fresh())->parent_id->toBe($this->academic->id)->is_active->toBeTrue()
            ->and(activitySince()->count())->toBe(0);
    })->with(['pick', 'openCreate', 'openEdit', 'openMove', 'save', 'move', 'setActive', 'openAppoint', 'choosePerson', 'appoint', 'confirmEnd', 'cancelEnd', 'end']);
});
