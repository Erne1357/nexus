<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\MenuEntry;
use App\Modules\Core\Navigation\Navigation;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

/*
| «Personal» de Comunicación y Difusión (`/comunicacion/personal`, communication::pages.staff). CO-02 (C10, confirmado el
| 24 de noviembre de 2026): la jefa nombra y termina solo el puesto «Personal de área», solo sobre su unidad y solo a
| personas del personal; el admin también puede. Nadie más entra: ni su personal ni el jefe de otra unidad.
*/

beforeEach(function () {
    $this->unit = communicationUnit();
    $this->head = communicationHead(['name' => 'Carla Comunicación']);
    $this->pablo = User::factory()->create(['name' => 'Pablo Pérez', 'username' => 'PEPP800101AAA']);
});

/**
 * Las entradas del área Comunicación y Difusión que ve `$user` en su menú.
 *
 * @return list<string>
 */
function communicationMenuOf(User $user): array
{
    test()->actingAs($user);

    $labels = [];

    foreach (app(Navigation::class)->visibleByArea(app(Actor::class)) as $area) {
        if ($area['area'] === NavigationArea::Comunicacion) {
            foreach ($area['groups'] as $group) {
                array_push($labels, ...array_map(fn (MenuEntry $entry): string => $entry->item->label, $group['entries']));
            }
        }
    }

    return $labels;
}

it('CO-02: la jefa de Comunicación nombra a una persona del personal como Personal de área en su unidad y la termina', function () {
    $older = RoleAssignment::factory()->create(['user_id' => User::factory()->create(['name' => 'Olga Ortiz'])->id, 'role' => Role::Personal, 'org_unit_id' => $this->unit->id, 'valid_from' => today()->subMonth()->toDateString()]);

    $this->actingAs($this->head)->get(route('communication.staff'))->assertOk()->assertSee('Olga Ortiz');

    $page = Livewire::actingAs($this->head)->test('communication::pages.staff')
        ->call('openAppoint')
        ->set('findPerson', 'pablo')
        ->assertSee('Pablo Pérez')
        ->call('choosePerson', $this->pablo->id)
        ->call('appoint')
        ->assertHasNoErrors();

    $assignment = RoleAssignment::query()->where('user_id', $this->pablo->id)->sole();

    expect($assignment)->role->toBe(Role::Personal)->org_unit_id->toBe($this->unit->id)->valid_to->toBeNull()
        ->and(lastToastOf($page)['title'])->toBe('Nombramiento creado')
        ->and(lastToastOf($page)['description'])->toBe('Pablo Pérez · Personal del Departamento de Comunicación y Difusión');

    $page->assertSee('Pablo Pérez')
        ->call('confirmEnd', $older->id)
        ->set('lastDay', today()->toDateString())
        ->call('end')
        ->assertHasNoErrors();

    expect($older->fresh()->valid_to->toDateString())->toBe(today()->toDateString())
        ->and(lastToastOf($page)['title'])->toBe('Nombramiento terminado');

    // Empezaba hoy: con ayer como último día, se quita.
    $page->call('confirmEnd', $assignment->id)->set('lastDay', today()->subDay()->toDateString())->call('end')->assertHasNoErrors();

    expect(RoleAssignment::query()->whereKey($assignment->id)->exists())->toBeFalse()
        ->and(lastToastOf($page)['title'])->toBe('Nombramiento quitado')
        ->and(communicationMenuOf($this->head))->toBe(['Personal', 'Fotos del personal', 'Fotos de alumnos', 'Avisos y atajos']);
});

it('CO-02: no puede nombrar otro puesto ni sobre otra unidad', function () {
    $hr = OrgUnit::factory()->create(['code' => 'RECURSOS-HUMANOS', 'name' => 'Departamento de Recursos Humanos', 'kind' => OrgUnitKind::Departamento]);
    $own = RoleAssignment::query()->where('user_id', $this->head->id)->sole();
    $hrStaff = RoleAssignment::factory()->create(['user_id' => User::factory()->create()->id, 'role' => Role::Personal, 'org_unit_id' => $hr->id]);
    $this->actingAs($this->head);

    expect(Gate::allows('appointUnitStaff', [RoleAssignment::class, $this->unit]))->toBeTrue()
        ->and(Gate::allows('appointUnitStaff', [RoleAssignment::class, $hr]))->toBeFalse()
        ->and(Gate::allows('create', RoleAssignment::class))->toBeFalse()
        ->and(Gate::allows('end', $own))->toBeFalse();

    $this->get(route('core.people'))->assertForbidden();
    $this->get(route('core.org-chart'))->assertForbidden();

    // La pantalla solo trabaja con el personal de área de su unidad: ni su propio puesto de jefa ni el personal de otra.
    Livewire::actingAs($this->head)->test('communication::pages.staff')->call('confirmEnd', $own->id)->assertNotFound();
    Livewire::actingAs($this->head)->test('communication::pages.staff')->call('confirmEnd', $hrStaff->id)->assertNotFound();

    // El puesto y la unidad no llegan del navegador: la pantalla no tiene de dónde recibirlos.
    expect(array_keys(Livewire::actingAs($this->head)->test('communication::pages.staff')->instance()->all()))
        ->not->toContain('role', 'unit', 'unitId', 'campus', 'career', 'modality');
});

it('CO-02: el personal de área no ve la pantalla', function () {
    $staff = communicationStaff(['name' => 'Sara Soto']);
    $hrHead = User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => OrgUnit::factory()->create(['code' => 'RECURSOS-HUMANOS', 'name' => 'Departamento de Recursos Humanos', 'kind' => OrgUnitKind::Departamento])->id])->create();
    $older = RoleAssignment::factory()->create(['user_id' => User::factory()->create()->id, 'role' => Role::Personal, 'org_unit_id' => $this->unit->id, 'valid_from' => today()->subMonth()->toDateString()]);

    foreach ([$staff, $hrHead] as $user) {
        $this->actingAs($user)->get(route('communication.staff'))->assertForbidden();

        Livewire::actingAs($user)->test('communication::pages.staff')->assertForbidden();
    }

    // Sobre una página que abrió la jefa, cada método se niega si después llega el personal.
    foreach ([['openAppoint'], ['choosePerson', $this->pablo->id], ['clearPerson'], ['appoint'], ['closeAppoint'], ['confirmEnd', $older->id], ['end'], ['cancelEnd']] as $call) {
        $page = Livewire::actingAs($this->head)->test('communication::pages.staff')
            ->call('openAppoint')
            ->call('choosePerson', $this->pablo->id)
            ->call('confirmEnd', $older->id);

        $this->actingAs($staff);
        $page->call(...$call)->assertForbidden();
    }

    expect(RoleAssignment::query()->where('user_id', $this->pablo->id)->exists())->toBeFalse()
        ->and($older->fresh()->valid_to)->toBeNull()
        ->and(communicationMenuOf($staff))->toBe(['Fotos del personal', 'Fotos de alumnos', 'Avisos y atajos'])
        ->and(communicationMenuOf($hrHead))->toBe([]);
});

it('CO-02: lo que AppointRole rechaza sale bajo la persona: nombrar dos veces a la misma persona', function () {
    RoleAssignment::factory()->create(['user_id' => $this->pablo->id, 'role' => Role::Personal, 'org_unit_id' => $this->unit->id, 'valid_from' => today()->subMonth()->toDateString()]);

    $page = Livewire::actingAs($this->head)->test('communication::pages.staff')
        ->call('openAppoint')
        ->call('choosePerson', $this->pablo->id)
        ->call('appoint')
        ->assertHasErrors(['personId']);

    expect(RoleAssignment::query()->where('user_id', $this->pablo->id)->count())->toBe(1)
        ->and(domOf($page)->querySelector('[data-testid="staff-person-error"]'))->not->toBeNull();
});

it('un alumno no aparece en la búsqueda', function () {
    $student = User::factory()->withRole(Role::Alumno)->create(['name' => 'Pablo Alumno', 'username' => '21110001']);
    $inactive = User::factory()->create(['name' => 'Pablo Baja', 'is_active' => false]);

    $page = Livewire::actingAs($this->head)->test('communication::pages.staff')
        ->call('openAppoint')
        ->set('findPerson', 'Pablo')
        ->assertSee('Pablo Pérez')
        ->assertDontSee('Pablo Alumno')
        ->assertDontSee('Pablo Baja')
        ->set('findPerson', '21110001')
        ->assertSee('Nadie del personal coincide con «21110001».');

    $page->call('choosePerson', $student->id)->assertNotFound();
    Livewire::actingAs($this->head)->test('communication::pages.staff')->call('openAppoint')->call('choosePerson', $inactive->id)->assertNotFound();

    expect(RoleAssignment::query()->where('user_id', $student->id)->where('role', Role::Personal)->exists())->toBeFalse();
});

it('el admin también nombra al personal de área desde la pantalla, y la ve en su menú', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('communication::pages.staff')
        ->call('openAppoint')
        ->call('choosePerson', $this->pablo->id)
        ->call('appoint')
        ->assertHasNoErrors();

    expect(RoleAssignment::query()->where('user_id', $this->pablo->id)->sole())->role->toBe(Role::Personal)->org_unit_id->toBe($this->unit->id)
        ->and(communicationMenuOf($admin))->toBe(['Personal', 'Fotos del personal', 'Fotos de alumnos']);
});
