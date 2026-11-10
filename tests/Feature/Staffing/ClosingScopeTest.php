<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use Livewire\Livewire;
use Tests\Support\Staffing\ClosingWorld;

/*
| Alcance de «Cierre de la carga» y «Avance del cierre» (tareas 4.1 y 4.2; CONTRIBUTING.md, «Checklist de seguridad»):
| entran quienes cierran la carga de algún departamento (el jefe, Subdirección y el admin) y, al tablero, quienes la
| reabren (Subdirección y el admin). El jefe de otro departamento no ve ni toca la carga ajena, ni por la URL ni
| llamando los métodos directo; el coordinador, el docente y el jefe de proyecto docente no entran.
*/

beforeEach(function () {
    $this->world = ClosingWorld::create();
    $this->jefe = $this->world->head();
    $this->group = $this->world->group(teacher: false);
    $this->cb = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $this->jefeCb = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->cb->id])->create();
    $this->world->group(load: $this->world->loadIn($this->world->campus, department: $this->cb));
});

/**
 * @return list<string>
 */
function closingMenuOf(User $user): array
{
    test()->actingAs($user);
    app(Actor::class)->refresh();

    return array_values(array_intersect(
        array_map(fn (NavigationItem $item): string => $item->label, app(Navigation::class)->visibleTo(app(Actor::class))),
        ['Cierre de la carga', 'Avance del cierre'],
    ));
}

it('un invitado va al login', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
})->with(['staffing.closing', 'staffing.closing-board']);

it('el coordinador, el docente y el jefe de proyecto docente reciben 403 y no ven las entradas del menú', function (Role $role) {
    $user = User::factory()->withRole($role, match ($role) {
        Role::Coordinador => ['campus_id' => $this->world->campus->id, 'career_id' => $this->group->owner_career_id],
        Role::JefeProyectoDocente => ['campus_id' => $this->world->campus->id, 'department_id' => $this->world->department->id],
        default => [],
    })->create();

    $this->actingAs($user)->get(route('staffing.closing'))->assertForbidden();
    $this->actingAs($user)->get(route('staffing.closing', ['departamento' => $this->world->department->id]))->assertForbidden();
    $this->actingAs($user)->get(route('staffing.closing-board'))->assertForbidden();

    expect(closingMenuOf($user))->toBe([]);
})->with([
    'coordinador' => [Role::Coordinador],
    'docente' => [Role::Docente],
    'jefe de proyecto docente' => [Role::JefeProyectoDocente],
]);

it('el jefe ve «Cierre de la carga» y no «Avance del cierre»; Subdirección y el admin, las dos', function () {
    expect(closingMenuOf($this->jefe))->toBe(['Cierre de la carga'])
        ->and(closingMenuOf(User::factory()->withRole(Role::Subdireccion)->create()))->toBe(['Cierre de la carga', 'Avance del cierre'])
        ->and(closingMenuOf(User::factory()->admin()->create()))->toBe(['Cierre de la carga', 'Avance del cierre']);
});

it('el tablero da 403 al jefe', function () {
    $this->actingAs($this->jefe)->get(route('staffing.closing-board'))->assertForbidden();
    Livewire::actingAs($this->jefe)->test('staffing::pages.closing-board')->assertForbidden();
});

it('el jefe de otro departamento no ve la carga ajena por la URL: 403, y entra a la suya', function () {
    $this->actingAs($this->jefeCb)->get(route('staffing.closing', ['departamento' => $this->world->department->id]))->assertForbidden();

    Livewire::actingAs($this->jefeCb)->test('staffing::pages.closing')
        ->assertSee('Ciencias Básicas')
        ->assertDontSee('Sistemas y Computación')
        ->assertDontSeeHtml('data-testid="closing-department"');
});

it('cada método da 403 al jefe de otro departamento, también sobre una página que abrió el jefe dueño', function (string $method, array $arguments) {
    $this->world->load->update(['status' => LoadStatus::Staffing]);
    $page = Livewire::actingAs($this->jefe)->test('staffing::pages.closing');
    $this->actingAs($this->jefeCb);
    app(Actor::class)->refresh();

    $page->call($method, ...array_map(fn (mixed $argument): mixed => $argument instanceof Closure ? $argument() : $argument, $arguments))->assertForbidden();

    expect($this->world->load->fresh()->status)->toBe(LoadStatus::Staffing)
        ->and($this->group->fresh()->close_justification)->toBeNull();
})->with([
    'pedir cerrar' => ['askClose', []],
    'cerrar' => ['close', []],
    'pedir reabrir' => ['askReopen', []],
    'reabrir' => ['reopen', []],
    'volver a asignación' => ['returnToStaffing', []],
    'dar visto bueno' => ['approve', []],
    'revisar de nuevo' => ['recheck', []],
    'ir a otra pantalla' => ['goTo', ['assign']],
]);

it('abrir el drawer con un grupo de otro departamento da 404 aunque el jefe pueda cerrar el suyo', function () {
    $foreign = $this->world->group(teacher: false, load: $this->world->load->newQuery()->where('department_id', $this->cb->id)->sole());
    $drawer = fn () => Livewire::actingAs($this->jefe)->test('staffing::components.closing-justify-drawer', ['loadId' => $this->world->load->id]);

    $drawer()->dispatch('open-justification', id: $foreign->id)->assertNotFound();
    $drawer()->dispatch('open-justification', id: 'no-es-uuid')->assertNotFound();
});

it('la lista y el drawer de justificaciones dan 403 al jefe de otro departamento, también sobre los que abrió el jefe dueño', function (string $component, string $method, array $arguments) {
    $opened = Livewire::actingAs($this->jefe)->test("staffing::components.{$component}", ['loadId' => $this->world->load->id])
        ->set($component === 'closing-justifications' ? 'selected' : 'justification', $component === 'closing-justifications' ? [$this->group->id] : 'Contratación en trámite');

    if ($component === 'closing-justify-drawer') {
        $opened->dispatch('open-justification', id: $this->group->id);
    } else {
        $opened->set('bulkReason', 'Contratación en trámite');
    }

    $this->actingAs($this->jefeCb);
    app(Actor::class)->refresh();

    $opened->call($method, ...$arguments)->assertForbidden();
    Livewire::actingAs($this->jefeCb)->test("staffing::components.{$component}", ['loadId' => $this->world->load->id])->assertForbidden();

    expect($this->group->fresh()->close_justification)->toBeNull();
})->with([
    'justificar en lote' => ['closing-justifications', 'justifySelected', []],
    'pedir el motivo del lote' => ['closing-justifications', 'askBulk', []],
    'mostrar más' => ['closing-justifications', 'showMore', []],
    'guardar una justificación' => ['closing-justify-drawer', 'save', []],
    'quitar una justificación' => ['closing-justify-drawer', 'remove', []],
]);

it('el jefe de proyecto docente recibe 403 al llamar los métodos de una página que abrió el jefe', function () {
    $proyecto = User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->world->campus->id, 'department_id' => $this->world->department->id])->create();
    $page = Livewire::actingAs($this->jefe)->test('staffing::pages.closing');
    $this->actingAs($proyecto);
    app(Actor::class)->refresh();

    $page->call('close')->assertForbidden();

    expect($this->world->load->fresh()->status)->toBe(LoadStatus::Staffing);
});

it('en el tablero, cada método da 403 al jefe sobre un tablero que abrió Subdirección', function (string $method, array $arguments) {
    $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();
    $page = Livewire::actingAs($subdireccion)->test('staffing::pages.closing-board')->call('open', $this->world->department->id);
    $this->actingAs($this->jefe);
    app(Actor::class)->refresh();

    $page->call($method, ...array_map(fn (mixed $argument): mixed => $argument instanceof Closure ? $argument() : $argument, $arguments))->assertForbidden();

    expect($this->world->load->fresh()->status)->toBe(LoadStatus::Staffing);
})->with([
    'abrir el detalle' => ['open', [fn () => test()->world->department->id]],
    'cerrar' => ['close', []],
    'reabrir' => ['reopen', []],
    'cambiar de campus' => ['selectCampus', ['C2']],
]);
