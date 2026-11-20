<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use Livewire\Livewire;

/*
| Ajustes de la lista de Docentes de la validación del 14 de noviembre de 2026 (tarea 1.21): por omisión lista los
| activos; filtra a los que no tienen correo institucional; la columna «Acceso» dice el estado del acceso de AC-04; el
| «Editar docente» de la lista captura el correo institucional con las reglas de la ficha (AC-06); y «Quitar de
| <Departamento>» lleva el nombre del departamento y dice la consecuencia (D10).
*/

beforeEach(function () {
    $this->sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->ii = Department::factory()->create(['code' => 'II', 'name' => 'Ingeniería Industrial']);

    $this->sinUsuario = Teacher::factory()->adscribedTo($this->sc)->create(['rfc' => 'PRUE000001AB1', 'name' => 'Ana Pérez']);
    $this->sinAcceso = Teacher::factory()->adscribedTo($this->sc)->create([
        'rfc' => 'PRUE000002AB1',
        'name' => 'Beto Ruiz',
        'user_id' => User::factory()->withRole(Role::Docente)->create(['username' => 'PRUE000002AB1', 'password' => null])->id,
    ]);
    $this->invitado = Teacher::factory()->adscribedTo($this->sc)->create([
        'rfc' => 'PRUE000003AB1',
        'name' => 'Carla Díaz',
        'institutional_email' => 'carla.diaz@cdjuarez.tecnm.mx',
        'user_id' => User::factory()->withRole(Role::Docente)->create(['username' => 'PRUE000003AB1', 'password' => null, 'access_invited_at' => now()])->id,
    ]);
    $this->debeCambiar = Teacher::factory()->adscribedTo($this->sc)->create([
        'rfc' => 'PRUE000004AB1',
        'name' => 'Darío Soto',
        'institutional_email' => 'dario.soto@cdjuarez.tecnm.mx',
        'user_id' => User::factory()->withRole(Role::Docente)->create(['username' => 'PRUE000004AB1', 'must_change_password' => true])->id,
    ]);
    $this->conAcceso = Teacher::factory()->adscribedTo($this->ii)->create([
        'rfc' => 'PRUE000005AB1',
        'name' => 'Elsa Mora',
        'institutional_email' => 'elsa.mora@cdjuarez.tecnm.mx',
        'user_id' => User::factory()->withRole(Role::Docente)->create(['username' => 'PRUE000005AB1', 'must_change_password' => false])->id,
    ]);
    $this->baja = Teacher::factory()->adscribedTo($this->ii)->inactive()->create(['rfc' => 'PRUE000006AB1', 'name' => 'Fer Luna']);

    $this->admin = User::factory()->admin()->create();
    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sc->id])->create();
    $this->subdireccion = User::factory()->withRole(Role::Subdireccion)->create();
});

/**
 * Los renglones de la tabla de Docentes que ve `$user` con la URL dada, por RFC, con su texto.
 *
 * @param  array<string, string>  $query
 * @return array<string, string>
 */
function accessRowsFor(User $user, array $query = []): array
{
    $response = test()->actingAs($user)->get(route('faculty.teachers', $query))->assertOk();

    return collect(domOf($response)->querySelectorAll('table tbody tr'))
        ->mapWithKeys(fn ($row): array => [(string) textOf($row->querySelector('td')) => textOf($row)])
        ->all();
}

it('por omisión lista solo a los docentes activos; sin filtro de estado los trae a todos', function () {
    expect(array_keys(accessRowsFor($this->admin)))->toBe(['PRUE000001AB1', 'PRUE000002AB1', 'PRUE000003AB1', 'PRUE000004AB1', 'PRUE000005AB1']);

    $page = Livewire::actingAs($this->admin)->test('faculty::pages.teachers')->assertSet('status', 'active');

    $page->set('status', '')->assertSee('PRUE000006AB1');
});

it('el aviso dice cuántos docentes activos no tienen correo institucional y «Ver solo esos» los filtra; «Ver todos» lo quita', function () {
    $notice = textOf(domOf($this->actingAs($this->admin)->get(route('faculty.teachers'))->assertOk())->querySelector('[data-testid="missing-emails"]'));

    expect($notice)->toBe('2 docentes activos sin correo institucional: sin él no reciben su invitación de acceso. Ver solo esos');

    $page = Livewire::actingAs($this->admin)->test('faculty::pages.teachers');

    $page->set('emailFilter', 'missing')
        ->assertSee('PRUE000001AB1')->assertSee('PRUE000002AB1')
        ->assertDontSee('PRUE000003AB1')->assertDontSee('PRUE000004AB1')->assertDontSee('PRUE000005AB1')
        ->assertSee('Solo los que no tienen correo institucional.')
        ->assertDontSee('PRUE000006AB1');

    $page->set('emailFilter', '')->assertSee('PRUE000005AB1');
});

it('AC-04: «Ver solo esos» vuelve el estado a «Activos», así la lista muestra exactamente a los que contó el aviso', function () {
    $this->baja->forceFill(['institutional_email' => null])->save();

    Livewire::actingAs($this->admin)->test('faculty::pages.teachers')
        ->set('status', '')
        ->assertSee('PRUE000006AB1')
        ->call('showMissingEmails')
        ->assertSet('status', 'active')
        ->assertSet('emailFilter', 'missing')
        ->assertViewHas('teachers', fn ($teachers): bool => $teachers->pluck('rfc')->all() === ['PRUE000001AB1', 'PRUE000002AB1']);
});

it('AC-04: el aviso cuenta con los mismos filtros de búsqueda, departamento y tipo que la lista', function () {
    $sinCorreoIi = Teacher::factory()->adscribedTo($this->ii)->create(['rfc' => 'PRUE000007AB1', 'name' => 'Gael Ríos']);
    $count = fn ($page): string => textOf(domOf($page->html())->querySelector('[data-testid="missing-emails"] .font-mono'));

    $page = Livewire::actingAs($this->admin)->test('faculty::pages.teachers');

    expect($count($page))->toBe('3');

    $page->set('departmentFilter', $this->ii->id);
    expect($count($page))->toBe('1');

    $page->call('showMissingEmails')
        ->assertViewHas('teachers', fn ($teachers): bool => $teachers->pluck('rfc')->all() === [$sinCorreoIi->rfc]);

    $page->set('emailFilter', '')->set('departmentFilter', '')->set('search', 'Beto');
    expect($count($page))->toBe('1');

    $page->set('search', '')->set('kindFilter', PositionKind::cases()[0]->value);
    expect(domOf($page->html())->querySelector('[data-testid="missing-emails"]'))->toBeNull();
});

it('el jefe cuenta solo a los de su departamento, y sin pendientes no hay aviso', function () {
    $jefeIi = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->ii->id])->create();

    expect(textOf(domOf($this->actingAs($this->jefe)->get(route('faculty.teachers'))->assertOk())->querySelector('[data-testid="missing-emails"]')))->toStartWith('2 docentes activos sin correo institucional');
    expect(domOf($this->actingAs($jefeIi)->get(route('faculty.teachers'))->assertOk())->querySelector('[data-testid="missing-emails"]'))->toBeNull();
});

it('un valor del filtro de correo que no existe no filtra', function () {
    Livewire::actingAs($this->admin)->test('faculty::pages.teachers')
        ->set('emailFilter', 'otro')
        ->assertSet('emailFilter', '')
        ->assertSee('PRUE000001AB1')->assertSee('PRUE000005AB1');
});

it('AC-04: la columna «Acceso» dice el estado del acceso de cada docente; sin usuario es «Sin acceso»', function () {
    $response = $this->actingAs($this->admin)->get(route('faculty.teachers'))->assertOk();
    $rows = accessRowsFor($this->admin);

    $response->assertSeeInOrder(['RFC', 'Nombre', 'Asignación', 'Horas base', 'Acceso', 'Estado']);
    expect($rows['PRUE000001AB1'])->toContain('Sin acceso')
        ->and($rows['PRUE000002AB1'])->toContain('Sin acceso')
        ->and($rows['PRUE000003AB1'])->toContain('Invitación enviada')
        ->and($rows['PRUE000004AB1'])->toContain('Debe cambiar su contraseña')
        ->and($rows['PRUE000005AB1'])->toContain('Con acceso')
        ->and(implode(' ', $rows))->not->toContain(' Sí ');
});

describe('«Editar docente» con el correo institucional (AC-06)', function () {
    it('AC-06: el admin lo captura en la lista con las mismas reglas que la ficha: sin espacios y en minúsculas', function () {
        Livewire::actingAs($this->admin)->test('faculty::pages.teachers')
            ->call('edit', $this->sinAcceso->id)
            ->assertSet('institutional_email', '')
            ->assertSee('Correo institucional')
            ->assertSee('@cdjuarez.tecnm.mx. A él va su invitación de acceso.')
            ->set('institutional_email', '  Beto.Ruiz@CDJuarez.TecNM.mx ')
            ->call('save')
            ->assertHasNoErrors();

        expect($this->sinAcceso->fresh()->institutional_email)->toBe('beto.ruiz@cdjuarez.tecnm.mx');
    });

    it('AC-06: otro dominio o un correo de otro docente salen como error del campo y no se guardan', function (string $email, string $message) {
        Livewire::actingAs($this->admin)->test('faculty::pages.teachers')
            ->call('edit', $this->sinAcceso->id)
            ->set('institutional_email', $email)
            ->call('save')
            ->assertHasErrors(['institutional_email' => $message]);

        expect($this->sinAcceso->fresh()->institutional_email)->toBeNull();
    })->with([
        'otro dominio' => ['beto@itcj.edu.mx', 'El correo institucional debe terminar en @cdjuarez.tecnm.mx.'],
        'de otro docente' => ['carla.diaz@cdjuarez.tecnm.mx', 'Otro docente ya tiene el correo institucional carla.diaz@cdjuarez.tecnm.mx.'],
    ]);

    it('AC-06: el jefe del departamento del docente lo captura; el de otro departamento no ve al docente', function () {
        Livewire::actingAs($this->jefe)->test('faculty::pages.teachers')
            ->call('edit', $this->sinAcceso->id)
            ->set('institutional_email', 'beto.ruiz@cdjuarez.tecnm.mx')
            ->call('save')
            ->assertHasNoErrors();

        expect($this->sinAcceso->fresh()->institutional_email)->toBe('beto.ruiz@cdjuarez.tecnm.mx');

        Livewire::actingAs($this->jefe)->test('faculty::pages.teachers')->call('edit', $this->conAcceso->id)->assertForbidden();
    });

    it('AC-06: a la Subdirección le sale deshabilitado con su motivo, edita los demás datos y el correo no cambia aunque lo mande el navegador', function () {
        $page = Livewire::actingAs($this->subdireccion)->test('faculty::pages.teachers')
            ->call('edit', $this->sinAcceso->id)
            ->assertSee('Solo su jefe de departamento o el administrador lo capturan.');

        expect(domOf($page->html())->querySelector('input[name="institutional_email"]')->hasAttribute('disabled'))->toBeTrue();

        $page->set('name', 'Beto Ruiz Paz')->set('institutional_email', 'beto.ruiz@cdjuarez.tecnm.mx')->call('save')
            ->assertHasErrors(['institutional_email' => 'Solo su jefe de departamento o el administrador lo capturan.']);

        expect($this->sinAcceso->fresh())->institutional_email->toBeNull()->name->toBe('Beto Ruiz');

        $page->set('institutional_email', '')->call('save')->assertHasNoErrors();

        expect($this->sinAcceso->fresh()->name)->toBe('Beto Ruiz Paz');
    });

    it('AC-06: el de un docente con otro puesto solo lo cambia el admin; al jefe le dice por qué', function () {
        RoleAssignment::factory()->for($this->sinAcceso->user)->create(['role' => Role::JefeDepartamento, 'org_unit_id' => $this->ii->id, 'valid_from' => today()->subMonth()->toDateString()]);

        Livewire::actingAs($this->jefe)->test('faculty::pages.teachers')
            ->call('edit', $this->sinAcceso->id)
            ->assertSee('Tiene otro puesto: su correo institucional solo lo cambia el administrador.')
            ->set('institutional_email', 'beto.ruiz@cdjuarez.tecnm.mx')
            ->call('save')
            ->assertHasErrors('institutional_email');

        Livewire::actingAs($this->admin)->test('faculty::pages.teachers')
            ->call('edit', $this->sinAcceso->id)
            ->set('institutional_email', 'beto.ruiz@cdjuarez.tecnm.mx')
            ->call('save')
            ->assertHasNoErrors();

        expect($this->sinAcceso->fresh()->institutional_email)->toBe('beto.ruiz@cdjuarez.tecnm.mx');
    });

    it('el alta no lleva el correo institucional: se captura al editar', function () {
        Livewire::actingAs($this->admin)->test('faculty::pages.teachers')
            ->call('create')
            ->assertDontSee('A él va su invitación de acceso.');
    });
});

describe('«Quitar de <Departamento>» (D10)', function () {
    it('D10: el menú «Acciones» nombra el departamento y la confirmación dice la consecuencia', function () {
        TeacherDepartment::factory()->for($this->sinUsuario)->for($this->ii)->create(['is_primary' => false, 'created_at' => now()->addSecond()]);
        $dom = domOf($this->actingAs($this->admin)->get(route('faculty.teachers'))->assertOk());
        $menu = collect($dom->querySelectorAll('table tbody tr'))
            ->first(fn ($row): bool => textOf($row->querySelector('td')) === 'PRUE000001AB1')
            ->querySelector('[role="menu"]');
        $items = collect($menu->querySelectorAll('[role="menuitem"]'))->keyBy(fn ($item): string => textOf($item));

        expect($items->keys()->all())->toContain('Quitar de Sistemas y Computación', 'Quitar de Ingeniería Industrial')
            ->and(implode(' ', $items->keys()->all()))->not->toContain('Quitar de SC')
            ->and($items['Quitar de Sistemas y Computación']->getAttribute('data-confirm-title'))->toBe('¿Quitar al docente PRUE000001AB1 de Sistemas y Computación?')
            ->and($items['Quitar de Sistemas y Computación']->getAttribute('x-confirm'))->toBe('Solo sale de Sistemas y Computación: sigue activo y su asignación principal pasa a Ingeniería Industrial.')
            ->and($items['Quitar de Ingeniería Industrial']->getAttribute('x-confirm'))->toBe('Solo sale de Ingeniería Industrial: sigue activo y su asignación principal no cambia.');

        $only = collect(domOf($this->actingAs($this->admin)->get(route('faculty.teachers'))->assertOk())->querySelectorAll('table tbody tr'))
            ->first(fn ($row): bool => textOf($row->querySelector('td')) === 'PRUE000002AB1')
            ->querySelector('[role="menuitem"]');

        expect(textOf($only))->toBe('Quitar de Sistemas y Computación')
            ->and($only->getAttribute('x-confirm'))->toBe('Es su último departamento: queda dado de baja y su cuenta se inhabilita.');
    });

    it('D10: el aviso de quitar dice el nombre del departamento', function () {
        $page = Livewire::actingAs($this->jefe)->test('faculty::pages.teachers')
            ->call('removeAdscription', $this->sinUsuario->adscriptions()->first()->id);

        expect(lastToastOf($page))->title->toBe('Docente dado de baja de Sistemas y Computación');
    });
});
