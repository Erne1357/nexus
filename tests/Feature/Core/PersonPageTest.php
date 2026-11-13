<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| La ficha de una persona en «Personas y puestos» (`/people/{user}`, core::pages.person, maqueta C): cifras, línea de
| vigencias, nombramientos con «Terminar…», «¿Quién ocupa un puesto?» y la bitácora. Nombrar y terminar los hacen
| AppointRole y EndRoleAssignment; la pantalla solo ofrece Administración, Subdirección Académica y Coordinador
| (AZ-S11) y el último nombramiento de Administración vigente no se termina (AZ-S12). Solo la ve el admin con ese perfil activo.
*/

beforeEach(function () {
    $this->travelTo('2026-11-03 10:00:00');

    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->isc = Career::factory()->create(['code' => 'ISC', 'name' => 'Ingeniería en Sistemas Computacionales']);
    $this->ind = Career::factory()->create(['code' => 'IND', 'name' => 'Ingeniería Industrial']);
    CareerCampus::factory()->create(['career_id' => $this->isc->id, 'campus_id' => $this->c2->id]);
    CareerCampus::factory()->create(['career_id' => $this->ind->id, 'campus_id' => $this->c1->id]);
    $this->systems = Department::factory()->create(['name' => 'Sistemas y Computación']);
    $this->academicId = app(OrgTree::class)->idOf(OrgTree::ACADEMIC);

    $this->admin = User::factory()->admin()->create(['name' => 'Ada Admin', 'username' => 'ada']);
    $this->rosa = User::factory()->create(['name' => 'Rosa Ruiz Almanza', 'username' => '19110001']);
    $this->beto = User::factory()->create(['name' => 'Beto Bravo', 'username' => '19110002']);

    $this->actingAs($this->admin);
    $this->activityBase = (int) Activity::max('id');
});

function personPage(User $person, ?User $as = null)
{
    return Livewire::actingAs($as ?? test()->admin)->test('core::pages.person', ['user' => $person->id]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function assign(User $user, Role $role, array $attributes = []): RoleAssignment
{
    return RoleAssignment::factory()->create(['user_id' => $user->id, 'role' => $role, 'valid_from' => '2026-08-01', ...$attributes]);
}

it('muestra la ficha: cifras, línea de vigencias, nombramientos y bitácora', function () {
    $coordinador = assign($this->rosa, Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->isc->id, 'valid_to' => '2027-07-31']);
    assign($this->rosa, Role::Docente, ['valid_from' => '2015-08-17']);
    assign($this->rosa, Role::JefeProyectoDocente, ['valid_from' => '2023-08-17', 'valid_to' => '2026-07-31', 'campus_id' => $this->c2->id, 'org_unit_id' => $this->systems->id]);
    activity()->performedOn($this->rosa)->causedBy($this->admin)->event('created')->withProperties(['role_assignment_id' => $coordinador->id])->log('Nombró a Rosa Ruiz Almanza como Coordinador de carrera · ISC · Presencial · Campus II');

    $this->get(route('core.people.show', $this->rosa->id))
        ->assertOk()
        ->assertSee('<title>Ficha de la persona · Nexus</title>', false)
        ->assertSeeInOrder(['Personas y puestos', 'RR', 'Rosa Ruiz Almanza', 'usuario', '19110001', 'Con 2 puestos vigentes', 'Nombrar'])
        ->assertSeeInOrder(['Buscar otra persona', 'Último nombramiento: ', '03/11/2026', ', por Ada Admin'])
        ->assertSeeInOrder(['Puestos vigentes', 'Anteriores', 'Con nombramiento desde', '17/08/2015', 'Próximo vencimiento', '31/07/2027', 'Coordinador de carrera · ISC · Presencial · Campus II'])
        ->assertSeeInOrder(['Línea de vigencias', 'Hoy', 'Nombramientos', 'Coordinador de carrera', 'Vigente', 'Faltan', '270', 'días', 'Docente', 'Sin alcance', 'Jefe de proyecto docente', 'Terminado'])
        ->assertSeeInOrder(['Quién ocupa un puesto?', 'Bitácora de sus puestos', 'Nombró a Rosa Ruiz Almanza como Coordinador de carrera · ISC · Presencial · Campus II', 'por Ada Admin'])
        ->assertSeeHtml('data-testid="vigencias"')
        ->assertSeeHtml('data-testid="vigencias-hoy"');
});

it('una persona sin nombramientos muestra sus estados vacíos', function () {
    personPage($this->beto)
        ->assertSee('Sin puesto vigente')
        ->assertSee('Todavía no tiene nombramientos.')
        ->assertSee('Sin fecha de fin')
        ->assertDontSee('Último nombramiento')
        ->assertSeeHtml('data-testid="vigencias-empty"');
});

it('una persona inactiva no se nombra, con el motivo escrito', function () {
    $inactive = User::factory()->create(['name' => 'Cris Inactivo', 'is_active' => false]);

    personPage($inactive)->assertSee('Usuario inactivo')->assertSee('El usuario está inactivo.')->assertSeeHtml('data-testid="appoint-blocker"');
    personPage($this->beto)->assertDontSeeHtml('data-testid="appoint-blocker"');
});

it('AZ-S17: nombra coordinador pidiendo modalidad y carrera, con el campus opcional', function () {
    $page = personPage($this->beto)->call('openAppoint')
        ->assertSet('role', 'coordinador')
        ->assertSet('modality', modalityOf('PRES')->id)
        ->assertSet('from', '2026-11-03')
        ->assertSeeHtml('data-testid="field-modality"')
        ->assertSeeHtml('data-testid="field-campus"')
        ->assertSeeHtml('data-testid="career-checklist"')
        ->assertDontSeeHtml('data-testid="field-unit"')
        ->assertSee('Pide una modalidad y una o varias carreras; el campus es opcional: sin campus, trabaja todos los de su modalidad. Se crea un nombramiento por carrera.')
        ->assertViewHas('options', fn (array $options): bool => array_column($options['careers'], 'id') === [$this->ind->id, $this->isc->id]);

    $page->set('campus', $this->c2->id)
        ->assertViewHas('options', fn (array $options): bool => array_column($options['careers'], 'id') === [$this->isc->id])
        ->set('campus', $this->c1->id)
        ->assertViewHas('options', fn (array $options): bool => array_column($options['careers'], 'id') === [$this->ind->id])
        ->assertSet('career', '')
        ->set('campus', $this->c2->id)
        ->set('career', $this->isc->id)
        ->call('appoint')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    expect(lastToastOf($page))->toMatchArray(['type' => 'success', 'title' => 'Nombramiento creado', 'description' => 'Coordinador de carrera · ISC · Presencial · Campus II'])
        ->and(RoleAssignment::where('user_id', $this->beto->id)->sole())
        ->role->toBe(Role::Coordinador)
        ->modality_id->toBe(modalityOf('PRES')->id)
        ->campus_id->toBe($this->c2->id)
        ->career_id->toBe($this->isc->id)
        ->org_unit_id->toBeNull();
});

it('AZ-S17: un campus que no existe es un error del campo, no «Todos los campus»', function () {
    personPage($this->beto)->call('openAppoint')
        ->set('campus', fakeKey(999_999))
        ->set('career', $this->isc->id)
        ->call('appoint')
        ->assertHasErrors(['campus' => 'Elige el campus.'])
        ->assertSet('showModal', true);

    expect(RoleAssignment::where('user_id', $this->beto->id)->exists())->toBeFalse();
});

it('AZ-S17: sin campus, el coordinador queda en todos los campus de su modalidad', function () {
    $page = personPage($this->beto)->call('openAppoint')
        ->set('career', $this->isc->id)
        ->call('appoint')
        ->assertHasNoErrors();

    expect(lastToastOf($page)['description'])->toBe('Coordinador de carrera · ISC · Presencial · Todos los campus')
        ->and(RoleAssignment::where('user_id', $this->beto->id)->sole())
        ->modality_id->toBe(modalityOf('PRES')->id)
        ->campus_id->toBeNull();
});

it('Administración no pide alcance y Subdirección pide su unidad', function () {
    personPage($this->beto)->call('openAppoint')
        ->set('role', 'admin')
        ->assertSee('Este puesto no tiene alcance: vale en todo el sistema.')
        ->assertDontSeeHtml('data-testid="field-campus"')
        ->assertDontSeeHtml('data-testid="field-unit"')
        ->set('role', 'subdireccion')
        ->assertSee('Este puesto pide la Subdirección Académica. Tiene un solo titular vigente.')
        ->assertSeeHtml('data-testid="field-unit"')
        ->assertDontSeeHtml('data-testid="field-campus"')
        ->assertViewHas('options', fn (array $options): bool => array_column($options['units'], 'id') === [$this->academicId]);

    personPage($this->beto)->call('openAppoint')->set('role', 'admin')->call('appoint')->assertHasNoErrors();

    expect(RoleAssignment::where('user_id', $this->beto->id)->sole()->role)->toBe(Role::Admin);
});

it('muestra en español los errores de la Action junto al campo', function () {
    vacate(Role::Subdireccion);
    assign($this->rosa, Role::Subdireccion, ['org_unit_id' => $this->academicId, 'valid_from' => '2026-01-01']);
    $before = RoleAssignment::count();

    personPage($this->beto)->call('openAppoint')
        ->set('role', 'subdireccion')
        ->set('unit', $this->academicId)
        ->call('appoint')
        ->assertHasErrors(['unit'])
        ->assertSee('ya tiene titular en esas fechas: Rosa Ruiz Almanza (desde 01/01/2026)', false)
        ->set('role', 'coordinador')
        ->set('modality', '')
        ->call('appoint')
        ->assertHasErrors(['modality', 'career'])
        ->assertSee('Elige la modalidad.')
        ->set('modality', modalityOf('PRES')->id)
        ->set('campus', $this->c2->id)
        ->set('career', $this->isc->id)
        ->set('to', '2026-01-01')
        ->call('appoint')
        ->assertHasErrors(['to'])
        ->assertSee('La fecha de fin no puede ser anterior a la de inicio.');

    $this->beto->update(['is_active' => false]);

    personPage($this->beto)->call('openAppoint')->set('role', 'admin')->call('appoint')->assertHasErrors(['user'])->assertSee('El usuario está inactivo.');

    expect(RoleAssignment::count())->toBe($before);
});

it('P1: nombra jefe de departamento pidiendo solo el departamento académico, y jefe de proyecto docente con modalidad, campus y departamento', function () {
    $page = personPage($this->beto)->call('openAppoint')
        ->set('role', 'jefe_departamento')
        ->assertSee('Este puesto pide el departamento académico o Servicios Escolares. Tiene un solo jefe vigente.')
        ->assertSeeHtml('data-testid="field-unit"')
        ->assertDontSeeHtml('data-testid="field-campus"')
        ->assertDontSeeHtml('data-testid="field-career"')
        ->set('unit', $this->systems->id)
        ->call('appoint')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    expect(RoleAssignment::where('user_id', $this->beto->id)->sole())
        ->role->toBe(Role::JefeDepartamento)
        ->org_unit_id->toBe($this->systems->id)
        ->campus_id->toBeNull();

    $page->call('openAppoint')
        ->set('role', 'jefe_proyecto_docente')
        ->assertSee('Este puesto pide modalidad y departamento académico. El campus es opcional: sin campus, trabaja todos los de su modalidad.')
        ->assertSeeHtml('data-testid="field-modality"')
        ->assertSeeHtml('data-testid="field-campus"')
        ->assertSeeHtml('data-testid="field-unit"')
        ->set('campus', $this->c2->id)
        ->set('unit', $this->systems->id)
        ->call('appoint')
        ->assertHasNoErrors();

    expect(RoleAssignment::where('user_id', $this->beto->id)->where('role', Role::JefeProyectoDocente)->sole())
        ->modality_id->toBe(modalityOf('PRES')->id)
        ->campus_id->toBe($this->c2->id)
        ->org_unit_id->toBe($this->systems->id);
});

it('P1: termina una jefatura desde la ficha de la persona', function () {
    $jefe = assign($this->rosa, Role::JefeDepartamento, ['org_unit_id' => $this->systems->id]);

    $page = personPage($this->rosa)
        ->assertSeeHtml('wire:click="confirmEnd(\''.$jefe->id.'\')"')
        ->call('confirmEnd', $jefe->id)
        ->set('lastDay', '2026-11-30')
        ->call('end')
        ->assertHasNoErrors();

    expect(lastToastOf($page))->toMatchArray(['type' => 'success', 'title' => 'Nombramiento terminado'])
        ->and($jefe->fresh()->valid_to->toDateString())->toBe('2026-11-30');
});

it('AZ-S7: un departamento con jefe vigente rechaza a otro y el error sale junto a la unidad', function () {
    assign($this->rosa, Role::JefeDepartamento, ['org_unit_id' => $this->systems->id, 'valid_from' => '2026-01-01']);
    $before = RoleAssignment::count();

    personPage($this->beto)->call('openAppoint')
        ->set('role', 'jefe_departamento')
        ->set('unit', $this->systems->id)
        ->call('appoint')
        ->assertHasErrors(['unit'])
        ->assertSee('Rosa Ruiz Almanza');

    expect(RoleAssignment::count())->toBe($before);
});

it('no termina el nombramiento de otra persona desde esta ficha', function () {
    $ajeno = assign($this->rosa, Role::JefeDepartamento, ['org_unit_id' => $this->systems->id]);

    personPage($this->beto)->call('confirmEnd', $ajeno->id)->assertNotFound()->assertSet('endingId', null);

    expect($ajeno->fresh()->valid_to)->toBeNull();
});

it('AZ-S11: no nombra ni termina el puesto de docente aunque se fuerce la llamada', function () {
    $page = personPage($this->beto)->call('openAppoint')->set('role', 'docente')->call('appoint');

    $page->assertHasErrors(['role'])->assertSee('Elige un puesto de la lista.');

    $docente = assign($this->rosa, Role::Docente);

    personPage($this->rosa)->call('confirmEnd', $docente->id)->call('end')->assertHasErrors(['lastDay'])->assertSee('ficha del docente');

    expect(RoleAssignment::where('user_id', $this->beto->id)->count())->toBe(0)
        ->and($docente->fresh()->valid_to)->toBeNull()
        ->and(activitySince()->count())->toBe(0);
});

it('AZ-S11: el puesto de docente se ve de solo lectura, con el aviso de dónde se maneja', function () {
    $docente = assign($this->rosa, Role::Docente);
    $jefe = assign($this->rosa, Role::JefeDepartamento, ['org_unit_id' => $this->systems->id]);

    $page = personPage($this->rosa)
        ->assertSee('El puesto de docente lo da la ficha del docente')
        ->assertSee('El puesto de docente se maneja en la ficha del docente.');

    expect($page->html())->toContain('confirmEnd(\''.$jefe->id.'\')')
        ->not->toContain('confirmEnd(\''.$docente->id.'\')');
});

it('AZ-S12: el admin puede terminar su propio puesto desde la ficha si queda otro admin', function () {
    $own = $this->admin->roleAssignments()->sole();
    assign($this->beto, Role::Admin);

    personPage($this->admin)
        ->assertSeeHtml('wire:click="confirmEnd(\''.$own->id.'\')"')
        ->call('confirmEnd', $own->id)
        ->set('lastDay', '2026-11-30')
        ->call('end')
        ->assertHasNoErrors();

    expect($own->fresh()->valid_to->toDateString())->toBe('2026-11-30');
});

it('AZ-S12: no puede terminar al último admin vigente (botón deshabilitado con su motivo)', function () {
    $own = $this->admin->roleAssignments()->sole();

    personPage($this->admin)
        ->assertDontSeeHtml('wire:click="confirmEnd(\''.$own->id.'\')"')
        ->assertSee('Es el único nombramiento de Administración que seguiría vigente')
        ->call('confirmEnd', $own->id)
        ->call('end')
        ->assertHasErrors(['lastDay']);

    expect($own->fresh()->valid_to)->toBeNull();
});

it('AZ-S12: «Terminar…» queda deshabilitado si dejaría días sin admin', function () {
    $own = $this->admin->roleAssignments()->sole();
    assign($this->beto, Role::Admin, ['valid_to' => '2026-11-30']);

    personPage($this->admin)
        ->assertDontSeeHtml('wire:click="confirmEnd(\''.$own->id.'\')"')
        ->assertSee('El 01/12/2026 nadie tendría un nombramiento de Administración vigente; nombra a otra persona antes de terminarlo.')
        ->call('confirmEnd', $own->id)
        ->set('lastDay', '2026-11-15')
        ->call('end')
        ->assertHasErrors(['lastDay']);

    expect($own->fresh()->valid_to)->toBeNull();
});

it('AZ-S12: con el único sucesor programado, «Terminar…» propone la víspera del sucesor y la Action dice el día exacto', function () {
    $own = $this->admin->roleAssignments()->sole();
    assign($this->beto, Role::Admin, ['valid_from' => '2026-12-01']);

    $page = personPage($this->admin)
        ->call('confirmEnd', $own->id)
        ->assertSet('lastDay', '2026-11-30')
        ->assertSee('Terminarlo antes dejaría días sin admin: el primer último día posible es el 30/11/2026.');

    $page->set('lastDay', '2026-11-15')
        ->call('end')
        ->assertHasErrors(['lastDay' => 'El 16/11/2026 nadie tendría un nombramiento de Administración vigente; nombra a otra persona antes de terminarlo.']);

    $page->set('lastDay', '2026-11-30')->call('end')->assertHasNoErrors();

    expect(lastToastOf($page))->title->toBe('Nombramiento terminado');
});

it('quien termina hoy su propio puesto de Administración vuelve a Inicio con un aviso, no a un 403', function () {
    $own = $this->admin->roleAssignments()->sole();
    $own->update(['valid_from' => '2026-08-01']);
    assign($this->beto, Role::Admin);

    $page = personPage($this->admin)
        ->call('confirmEnd', $own->id)
        ->set('lastDay', '2026-11-02')
        ->call('end')
        ->assertHasNoErrors()
        ->assertRedirect(route('home'));

    expect(lastToastOf($page))->title->toBe('Terminaste tu puesto de Administración.')
        ->and($own->fresh()->valid_to->toDateString())->toBe('2026-11-02');
});

it('termina con la fecha elegida y avisa según el resultado', function () {
    $current = assign($this->rosa, Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->isc->id]);
    $soon = assign($this->rosa, Role::Admin, ['valid_to' => '2026-12-01']);
    vacate(Role::Subdireccion);
    $future = assign($this->rosa, Role::Subdireccion, ['org_unit_id' => $this->academicId, 'valid_from' => '2027-02-01']);

    $page = personPage($this->rosa)
        ->call('confirmEnd', $current->id)
        ->assertSet('lastDay', '2026-11-03')
        ->assertSee('El puesto deja de ejercerse después de esa fecha. Queda en el historial y en la bitácora.')
        ->set('lastDay', '2026-11-30')
        ->call('end')
        ->assertHasNoErrors()
        ->assertSet('endingId', null);

    expect(lastToastOf($page))->toMatchArray(['type' => 'success', 'title' => 'Nombramiento terminado', 'description' => 'Último día: 30/11/2026'])
        ->and($current->fresh()->valid_to->toDateString())->toBe('2026-11-30');

    $page->call('confirmEnd', $soon->id)->set('lastDay', '2026-12-15')->call('end');

    expect(lastToastOf($page))->toMatchArray(['type' => 'info', 'title' => 'Sin cambios', 'description' => 'Ese nombramiento ya termina el 01/12/2026.'])
        ->and($soon->fresh()->valid_to->toDateString())->toBe('2026-12-01');

    $page->call('confirmEnd', $future->id)
        ->assertSee('Todavía no empieza: si la fecha de fin es anterior a su inicio, el nombramiento se quita y queda en la bitácora.')
        ->set('lastDay', '2026-11-03')
        ->call('end');

    expect(lastToastOf($page))->toMatchArray(['type' => 'success', 'title' => 'Nombramiento quitado', 'description' => 'Todavía no empezaba.'])
        ->and(RoleAssignment::find($future->id))->toBeNull();
});

it('P4 y P5: el recuadro de un nombramiento que empieza hoy avisa que una fecha anterior lo quita, y ayer lo quita', function () {
    $today = assign($this->rosa, Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->isc->id, 'valid_from' => '2026-11-03']);

    $page = personPage($this->rosa)
        ->call('confirmEnd', $today->id)
        ->assertSee('Empieza hoy: si la fecha de fin es anterior a su inicio, el nombramiento se quita y queda en la bitácora.')
        ->assertDontSee('Queda en el historial y en la bitácora.')
        ->set('lastDay', '2026-11-02')
        ->call('end')
        ->assertHasNoErrors();

    expect(lastToastOf($page))->toMatchArray(['type' => 'success', 'title' => 'Nombramiento quitado', 'description' => 'Empezaba hoy.'])
        ->and(RoleAssignment::find($today->id))->toBeNull();
});

it('cancelar el recuadro de terminar no escribe nada, y una fecha vacía se pide', function () {
    $current = assign($this->rosa, Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->isc->id]);

    personPage($this->rosa)->call('confirmEnd', $current->id)->assertSeeHtml('data-testid="end-box"')
        ->set('lastDay', '')->call('end')->assertHasErrors(['lastDay'])->assertSee('Escribe la fecha de fin.')
        ->call('cancelEnd')->assertSet('endingId', null)->assertDontSeeHtml('data-testid="end-box"');

    expect($current->fresh()->valid_to)->toBeNull();
});

it('¿Quién ocupa un puesto? da el titular o dice que no hay', function () {
    $ana = User::factory()->create(['name' => 'Ana Coordina', 'username' => '19110010']);
    assign($ana, Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->isc->id, 'valid_from' => '2026-08-01']);

    personPage($this->beto)
        ->assertSee('Elige el alcance para ver quién ocupa el puesto.')
        ->set('whoCampus', $this->c2->id)
        ->set('whoCareer', $this->isc->id)
        ->assertSee('Ana Coordina')
        ->assertSee('19110010')
        ->assertSee('desde')
        ->assertSee('01/08/2026')
        ->set('whoCampus', $this->c1->id)
        ->set('whoCareer', $this->ind->id)
        ->assertSee('Sin coordinador de carrera vigente')
        ->assertSee('Nadie ocupa Coordinador de carrera · IND · Presencial · Campus I hoy.')
        ->set('whoRole', 'admin')
        ->assertSee('Ada Admin')
        ->assertViewHas('whoRoles', fn (array $roles): bool => ! in_array('docente', array_column($roles, 'id'), true));
});

it('busca otra persona por nombre o usuario', function () {
    personPage($this->rosa)
        ->set('find', 'beto')->assertSee('Beto Bravo')->assertSee('19110002')
        ->set('find', '19110001')->assertViewHas('found', fn ($found): bool => $found->isEmpty())
        ->set('find', 'zzz')->assertSee('Nadie coincide con «zzz».')
        ->set('find', '')->assertDontSee('Nadie coincide');
});

describe('otros perfiles', function () {
    it('un coordinador recibe 403 en la ruta y en cada método', function (string $method) {
        $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->isc->id])->create();
        $coordinador->roleAssignments()->sole();
        $target = assign($this->rosa, Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->isc->id]);

        $this->actingAs($coordinador)->get(route('core.people.show', $this->rosa->id))->assertForbidden();

        $page = personPage($this->rosa);

        match ($method) {
            'appoint' => $page->call('openAppoint')->set('role', 'admin'),
            'end' => $page->call('confirmEnd', $target->id),
            default => null,
        };

        $this->actingAs($coordinador);

        $arguments = $method === 'confirmEnd' ? [$target->id] : [];

        if ($method === 'whoRole') {
            $page->set('whoRole', 'admin')->assertForbidden();
        } else {
            $page->call($method, ...$arguments)->assertForbidden();
        }

        expect($target->fresh()->valid_to)->toBeNull()
            ->and(RoleAssignment::where('user_id', $this->rosa->id)->count())->toBe(1)
            ->and(activitySince()->count())->toBe(0);
    })->with(['openAppoint', 'appoint', 'confirmEnd', 'end', 'cancelEnd', 'whoRole']);

    it('la misma persona con Coordinación activa recibe 403', function () {
        $both = User::factory()->admin()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->isc->id])->create();

        actingAsRole($both, Role::Admin)->get(route('core.people.show', $this->rosa->id))->assertOk();
        actingAsRole($both, Role::Coordinador)->get(route('core.people.show', $this->rosa->id))->assertForbidden();
    });
});

it('un id que no es uuid, o que no existe, da 404', function (string $id) {
    $this->get('/people/'.$id)->assertNotFound();
})->with(['no-es-uuid', '018f0000-0000-7000-8000-000000000001']);
