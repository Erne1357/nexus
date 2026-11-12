<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\RoleSummaryQuery;
use Livewire\Livewire;

/*
| «Nombrar» como cajón con casillas (U3 opción A) y la pestaña «Nombramientos» de «Personas y puestos»: el
| coordinador se nombra sobre varias carreras de una modalidad, con campus opcional (M5, MS19, AZ-S17); la lista plana
| muestra modalidad y «Todos los campus». Solo el admin nombra.
*/

beforeEach(function () {
    $this->travelTo('2026-11-03 10:00:00');

    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->ii = Department::factory()->create(['code' => 'II', 'name' => 'Ingeniería Industrial']);
    $this->isc = Career::factory()->create(['code' => 'ISC', 'name' => 'Ingeniería en Sistemas Computacionales', 'department_id' => $this->sc->id]);
    $this->itic = Career::factory()->create(['code' => 'ITIC', 'name' => 'Ingeniería en TIC', 'department_id' => $this->sc->id]);
    $this->iind = Career::factory()->create(['code' => 'IIND', 'name' => 'Ingeniería Industrial', 'department_id' => $this->ii->id]);
    $this->ilog = Career::factory()->create(['code' => 'ILOG', 'name' => 'Ingeniería Logística', 'department_id' => $this->ii->id]);

    foreach ([$this->isc, $this->itic, $this->iind] as $career) {
        CareerCampus::factory()->create(['career_id' => $career->id, 'campus_id' => $this->c2->id]);
    }

    CareerCampus::factory()->create(['career_id' => $this->ilog->id, 'campus_id' => $this->c1->id]);

    $this->admin = User::factory()->admin()->create(['name' => 'Ada Admin', 'username' => 'ada']);
    $this->beto = User::factory()->create(['name' => 'Beto Bravo', 'username' => '19110002']);
    $this->pres = modalityOf('PRES');
});

function drawerPage(User $person, ?User $as = null)
{
    return Livewire::actingAs($as ?? test()->admin)->test('core::pages.person', ['user' => $person->id]);
}

it('lista las carreras por departamento con quién las coordina hoy', function () {
    $dani = User::factory()->create(['name' => 'Daniel Ríos']);
    RoleAssignment::factory()->create(['user_id' => $dani->id, 'role' => Role::Coordinador, 'career_id' => $this->isc->id, 'modality_id' => $this->pres->id, 'campus_id' => null, 'valid_from' => '2026-08-01']);

    drawerPage($this->beto)->call('openAppoint')
        ->set('campus', $this->c2->id)
        ->assertSee('Sistemas y Computación')->assertSeeHtml('data-testid="career-ISC"')->assertSee('Daniel Ríos · todos los campus', false)->assertSee('Sin coordinador en C2')->assertSee('IIND')
        ->assertDontSeeHtml('data-testid="career-ILOG"')
        ->assertSeeHtml('data-testid="appoint-drawer"');
});

it('M5: nombra al coordinador sobre varias carreras, una por nombramiento, con una sola respuesta', function () {
    $page = drawerPage($this->beto)->call('openAppoint')
        ->set('campus', $this->c2->id)
        ->set('careers', [$this->isc->id, $this->iind->id])
        ->call('appoint')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    expect(lastToastOf($page))->toMatchArray(['type' => 'success', 'title' => 'Nombramientos creados'])
        ->and(RoleAssignment::where('user_id', $this->beto->id)->count())->toBe(2)
        ->and(RoleAssignment::where('user_id', $this->beto->id)->pluck('career_id')->sort()->values()->all())->toBe(collect([$this->isc->id, $this->iind->id])->sort()->values()->all())
        ->and(RoleAssignment::where('user_id', $this->beto->id)->pluck('campus_id')->unique()->all())->toBe([$this->c2->id])
        ->and(RoleAssignment::where('user_id', $this->beto->id)->pluck('modality_id')->unique()->all())->toBe([$this->pres->id]);
});

it('AZ-S17: el campus es opcional: sin campus, los nombramientos valen en todos los campus de la modalidad', function () {
    drawerPage($this->beto)->call('openAppoint')
        ->set('careers', [$this->isc->id, $this->ilog->id])
        ->call('appoint')
        ->assertHasNoErrors();

    expect(RoleAssignment::where('user_id', $this->beto->id)->count())->toBe(2)
        ->and(RoleAssignment::where('user_id', $this->beto->id)->whereNotNull('campus_id')->exists())->toBeFalse();
});

it('«Todas las de Presencial en Campus II» marca las carreras que se ofrecen en ese campus', function () {
    $page = drawerPage($this->beto)->call('openAppoint')
        ->set('campus', $this->c2->id)
        ->call('selectAllInCampus')
        ->assertSet('careers', fn (array $ids): bool => collect($ids)->sort()->values()->all() === collect([$this->isc->id, $this->itic->id, $this->iind->id])->sort()->values()->all())
        ->assertSee('Todas las de Presencial en Campus II')
        ->assertSee('Nombrar en 3 carreras');

    $page->call('selectAllInModality')
        ->assertSet('campus', '')
        ->assertSet('careers', fn (array $ids): bool => count($ids) === 4)
        ->assertSee('Todas las de Presencial');
});

it('marca y desmarca las carreras de un departamento', function () {
    drawerPage($this->beto)->call('openAppoint')
        ->call('toggleDepartment', $this->sc->id)
        ->assertSet('careers', fn (array $ids): bool => collect($ids)->sort()->values()->all() === collect([$this->isc->id, $this->itic->id])->sort()->values()->all())
        ->call('toggleDepartment', $this->sc->id)
        ->assertSet('careers', []);
});

it('AZ-S17: un campus que no existe se rechaza y no nombra a nadie', function () {
    drawerPage($this->beto)->call('openAppoint')
        ->set('campus', fakeKey(999_999))
        ->set('careers', [$this->isc->id])
        ->call('appoint')
        ->assertHasErrors(['campus' => 'Elige el campus.'])
        ->assertSet('showModal', true);

    expect(RoleAssignment::where('user_id', $this->beto->id)->exists())->toBeFalse();
});

it('sin carreras elegidas pide elegir al menos una', function () {
    drawerPage($this->beto)->call('openAppoint')
        ->call('appoint')
        ->assertHasErrors(['career' => 'Elige al menos una carrera.']);

    expect(RoleAssignment::where('user_id', $this->beto->id)->exists())->toBeFalse();
});

it('MS16: la modalidad solo se elige si hay más de una activa', function () {
    drawerPage($this->beto)->call('openAppoint')
        ->assertDontSeeHtml('data-testid="modality-picker"')
        ->assertSeeHtml('data-testid="modality-fixed"');

    modalityOf('EAD')->update(['is_active' => true]);

    drawerPage($this->beto)->call('openAppoint')
        ->assertSeeHtml('data-testid="modality-picker"')
        ->assertDontSeeHtml('data-testid="modality-fixed"');
});

it('un jefe de proyecto docente se nombra en el mismo cajón con modalidad, departamento y campus opcional', function () {
    drawerPage($this->beto)->call('openAppoint')
        ->set('role', 'jefe_proyecto_docente')
        ->assertSeeHtml('data-testid="appoint-drawer"')
        ->assertDontSeeHtml('data-testid="career-checklist"')
        ->set('unit', $this->sc->id)
        ->call('appoint')
        ->assertHasNoErrors();

    expect(RoleAssignment::where('user_id', $this->beto->id)->sole())
        ->role->toBe(Role::JefeProyectoDocente)
        ->campus_id->toBeNull()
        ->modality_id->toBe($this->pres->id);
});

it('solo el admin abre el cajón y nombra: un coordinador recibe 403 en cada método', function (string $method) {
    $coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->isc->id])->create();
    $before = RoleAssignment::count();

    $this->actingAs($coordinator)->get(route('core.people.show', $this->beto->id))->assertForbidden();

    $page = drawerPage($this->beto)->call('openAppoint')->set('campus', $this->c2->id)->set('careers', [$this->isc->id]);

    $this->actingAs($coordinator);

    $arguments = $method === 'toggleDepartment' ? [$this->sc->id] : [];
    $page->call($method, ...$arguments)->assertForbidden();

    expect(RoleAssignment::count())->toBe($before);
})->with(['openAppoint', 'appoint', 'selectAllInCampus', 'selectAllInModality', 'toggleDepartment']);

it('la pestaña «Nombramientos» recibe 403 si quien la usa ya no es admin', function () {
    $coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->isc->id])->create();
    $page = Livewire::actingAs($this->admin)->test('core::pages.people');

    $this->actingAs($coordinator);

    $page->call('showTab', 'appointments')->assertForbidden();
});

it('«Ya la coordina» cuenta solo el alcance exacto: sin campus, una coordinación de C2 no cuenta y se puede ampliar', function () {
    appointment($this->beto, Role::Coordinador, ['career_id' => $this->isc->id, 'modality_id' => $this->pres->id, 'campus_id' => $this->c2->id]);

    $page = drawerPage($this->beto)->call('openAppoint')
        ->assertDontSee('Ya la coordina')
        ->call('selectAllInModality')
        ->assertSet('careers', fn (array $ids): bool => in_array($this->isc->id, $ids, true) && count($ids) === 4)
        ->set('campus', $this->c2->id)
        ->assertSee('Ya la coordina en C2')
        ->assertSeeHtml('data-testid="career-ISC"')
        ->call('selectAllInCampus')
        ->assertSet('careers', fn (array $ids): bool => ! in_array($this->isc->id, $ids, true) && count($ids) === 2)
        ->assertSee('Nombrar en 2 carreras');

    $page->set('campus', '')->set('careers', [$this->isc->id])->call('appoint')->assertHasNoErrors();

    expect(RoleAssignment::where('user_id', $this->beto->id)->whereNull('campus_id')->sole()->career_id)->toBe($this->isc->id);
});

it('«Ya la coordina» con una coordinación de todos los campus vale para cualquier campus', function () {
    appointment($this->beto, Role::Coordinador, ['career_id' => $this->isc->id, 'modality_id' => $this->pres->id, 'campus_id' => null]);

    drawerPage($this->beto)->call('openAppoint')
        ->assertSee('Ya la coordina en todos los campus')
        ->set('campus', $this->c2->id)
        ->assertSee('Ya la coordina en todos los campus')
        ->call('selectAllInCampus')
        ->assertSet('careers', fn (array $ids): bool => ! in_array($this->isc->id, $ids, true));
});

it('un campus nuevo conserva las carreras marcadas que también se ofrecen en él', function () {
    drawerPage($this->beto)->call('openAppoint')
        ->set('careers', [$this->isc->id, $this->ilog->id])
        ->set('campus', $this->c2->id)
        ->assertSet('careers', [$this->isc->id])
        ->set('campus', $this->c1->id)
        ->assertSet('careers', []);
});

it('la búsqueda solo oculta renglones: los atajos, la cuenta y el departamento usan todas las carreras', function () {
    drawerPage($this->beto)->call('openAppoint')
        ->set('campus', $this->c2->id)
        ->set('careerSearch', 'IIND')
        ->assertSeeHtml('data-testid="career-IIND"')
        ->assertDontSeeHtml('data-testid="career-ISC"')
        ->assertSee('(3)')
        ->call('toggleDepartment', $this->sc->id)
        ->assertSet('careers', fn (array $ids): bool => collect($ids)->sort()->values()->all() === collect([$this->isc->id, $this->itic->id])->sort()->values()->all())
        ->call('selectAllInCampus')
        ->assertSet('careers', fn (array $ids): bool => count($ids) === 3);
});

it('sin modalidad elegida solo pide la modalidad si ya hay carreras marcadas', function () {
    drawerPage($this->beto)->call('openAppoint')
        ->set('modality', '')
        ->set('careers', [$this->isc->id])
        ->call('appoint')
        ->assertHasErrors(['modality'])
        ->assertHasNoErrors(['career']);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function appointment(User $user, Role $role, array $attributes = []): RoleAssignment
{
    return RoleAssignment::factory()->create(['user_id' => $user->id, 'role' => $role, 'valid_from' => '2026-08-01', ...$attributes]);
}

it('la pestaña «Nombramientos» lista una fila por nombramiento con modalidad y «Todos los campus»', function () {
    appointment($this->beto, Role::Coordinador, ['career_id' => $this->isc->id, 'modality_id' => $this->pres->id, 'campus_id' => null, 'valid_from' => '2026-07-01']);
    appointment($this->beto, Role::Coordinador, ['career_id' => $this->iind->id, 'modality_id' => $this->pres->id, 'campus_id' => $this->c2->id, 'valid_to' => '2027-07-31']);

    Livewire::actingAs($this->admin)->test('core::pages.people')
        ->call('showTab', 'appointments')
        ->assertSeeInOrder(['Persona', 'Puesto', 'Carrera o departamento', 'Modalidad', 'Campus', 'Vigencia'])
        ->assertSeeInOrder(['Beto Bravo', 'Coordinador de carrera', 'ISC'])
        ->assertSeeInOrder(['ISC', 'Presencial', 'Todos los campus'])
        ->assertSee('Desde 01/07/2026')
        ->assertSeeInOrder(['Beto Bravo', 'IIND', 'Presencial', 'Campus II'])
        ->assertSee('01/08/2026 – 31/07/2027');
});

it('la pestaña «Nombramientos» filtra por persona, puesto, modalidad, campus y vigencia', function () {
    $rosa = User::factory()->create(['name' => 'Rosa Ruiz Almanza']);
    modalityOf('EAD')->update(['is_active' => true]);
    appointment($this->beto, Role::Coordinador, ['career_id' => $this->isc->id, 'modality_id' => $this->pres->id, 'campus_id' => $this->c2->id]);
    appointment($rosa, Role::JefeProyectoDocente, ['org_unit_id' => $this->sc->id, 'modality_id' => modalityOf('EAD')->id, 'campus_id' => null]);
    appointment($rosa, Role::Coordinador, ['career_id' => $this->itic->id, 'modality_id' => $this->pres->id, 'campus_id' => $this->c1->id, 'valid_to' => '2026-09-30']);

    $page = Livewire::actingAs($this->admin)->test('core::pages.people')->call('showTab', 'appointments');

    $page->assertSee('ISC')->assertSee('Sistemas y Computación')->assertDontSee('ITIC')
        ->set('appCurrent', 'all')->assertSee('ITIC')
        ->set('appCurrent', 'current')
        ->set('appSearch', 'Beto')->assertSee('Beto Bravo')->assertDontSee('Rosa Ruiz')
        ->set('appSearch', '')
        ->set('appRole', 'jefe_proyecto_docente')->assertSee('Rosa Ruiz')->assertDontSee('Beto Bravo')
        ->set('appRole', '')
        ->set('appModality', modalityOf('EAD')->id)->assertSee('Rosa Ruiz')->assertDontSee('Beto Bravo')
        ->set('appModality', '')
        ->set('appCampus', $this->c2->id)->assertSee('Beto Bravo');
});

it('la pestaña «Nombramientos» no la ve quien no es admin', function () {
    $coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->isc->id])->create();

    Livewire::actingAs($coordinator)->test('core::pages.people')->assertForbidden();
});

it('la credencial separa la unidad y la modalidad del jefe de proyecto docente', function () {
    appointment($this->beto, Role::JefeProyectoDocente, ['org_unit_id' => $this->sc->id, 'modality_id' => $this->pres->id, 'campus_id' => $this->c2->id]);
    $summary = app(RoleSummaryQuery::class)->forHeldRoles(app(Actor::class)->forUser($this->beto->fresh()));

    expect($summary['jefe_proyecto_docente'])->toBe('Jefe de proyecto docente · Sistemas y Computación · Presencial (C2)');
});

it('la credencial dice la modalidad del puesto que la lleva', function () {
    appointment($this->beto, Role::Coordinador, ['career_id' => $this->isc->id, 'modality_id' => $this->pres->id, 'campus_id' => $this->c2->id]);
    $summary = app(RoleSummaryQuery::class)->forHeldRoles(app(Actor::class)->forUser($this->beto->fresh()));

    expect($summary['coordinador'])->toBe('Coordinador de carrera · ISC · Presencial (C2)');
});
