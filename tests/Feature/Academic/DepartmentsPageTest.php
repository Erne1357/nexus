<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Core\Queries\ScopedContext;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| CRUD de Departamentos (`/academic/departments`, academic::pages.departments). Solo el admin
| (reglas-de-negocio.md, "Aprobaciones y permisos", fila "Administrar catálogos de Academic"): ve la
| tabla con sus conteos, busca, filtra, da de alta, edita, activa, desactiva y borra. Cada acción del
| componente vuelve a autorizar, aunque se llame directo con una página que abrió otra sesión.
*/

beforeEach(function () {
    $this->sis = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    $this->ele = Department::factory()->create(['code' => 'ELE', 'name' => 'Eléctrica-Electrónica']);
    $this->old = Department::factory()->inactive()->create(['code' => 'OLD', 'name' => 'Departamento Antiguo']);

    Career::factory()->count(2)->create(['department_id' => $this->sis->id]);
    Career::factory()->create(['department_id' => $this->sis->id])->delete();
    Subject::factory()->count(3)->create(['department_id' => $this->sis->id]);

    $this->admin = User::factory()->admin()->create();
});

/**
 * @param  list<string>  $codes
 * @return Closure(mixed): bool
 */
function listsDepartments(array $codes): Closure
{
    return fn ($departments): bool => $departments->pluck('code')->all() === $codes;
}

function seesDepartmentsMenu(User $user): bool
{
    test()->actingAs($user);

    return collect(app(Navigation::class)->visibleTo(app(ScopedContext::class)))
        ->contains(fn (NavigationItem $item): bool => $item->label === 'Departamentos');
}

describe('admin', function () {
    it('ve la tabla con clave, nombre, conteos de carreras y materias sin borrar, y estado', function () {
        $this->actingAs($this->admin)
            ->get(route('academic.departments'))
            ->assertOk()
            ->assertSeeInOrder(['Clave', 'Nombre', 'Carreras', 'Materias', 'Estado'])
            ->assertSeeInOrder(['ELE', 'Eléctrica-Electrónica', 'OLD', 'Departamento Antiguo', 'Inactivo', 'SIS', 'Sistemas y Computación']);

        $rows = collect(domOf($this->actingAs($this->admin)->get(route('academic.departments')))->querySelectorAll('table tbody tr'))
            ->mapWithKeys(fn ($row) => [textOf($row->querySelector('td')) => preg_split('/\s+/', textOf($row))]);

        expect($rows['SIS'])->toContain('2', '3')
            ->and($rows['ELE'])->toContain('0');
    });

    it('en el celular apila cada departamento en data-testid="department-cards"', function () {
        $cards = domOf($this->actingAs($this->admin)->get(route('academic.departments')))
            ->querySelectorAll('[data-testid="department-cards"] > li');

        expect(count($cards))->toBe(3)
            ->and(textOf($cards[2]))->toContain('SIS Sistemas y Computación Activo')
            ->and(textOf($cards[2]))->toContain('2 carreras · 3 materias');
    });

    it('busca por clave o nombre y filtra por estado', function () {
        Livewire::actingAs($this->admin)
            ->test('academic::pages.departments')
            ->assertViewHas('departments', listsDepartments(['ELE', 'OLD', 'SIS']))
            ->set('search', 'antiguo')
            ->assertViewHas('departments', listsDepartments(['OLD']))
            ->set('search', 'sis')
            ->assertViewHas('departments', listsDepartments(['SIS']))
            ->set('search', '')
            ->set('status', 'inactive')
            ->assertViewHas('departments', listsDepartments(['OLD']))
            ->set('status', 'active')
            ->assertViewHas('departments', listsDepartments(['ELE', 'SIS']));
    });

    it('da de alta un departamento desde el modal y lo confirma con un toast', function () {
        $page = Livewire::actingAs($this->admin)
            ->test('academic::pages.departments')
            ->call('create')
            ->assertSet('showModal', true)
            ->set('code', 'ind')
            ->set('name', 'Industrial')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showModal', false);

        expect(Department::where('code', 'IND')->sole()->name)->toBe('Industrial')
            ->and(lastToastOf($page))->type->toBe('success')->title->toBe('Departamento creado')->description->toBe('IND · Industrial');
    });

    it('muestra en español los errores de validación y el de clave repetida', function () {
        Livewire::actingAs($this->admin)
            ->test('academic::pages.departments')
            ->call('create')
            ->call('save')
            ->assertHasErrors(['code' => 'required', 'name' => 'required'])
            ->assertSee('El campo clave es obligatorio.')
            ->set('code', 'sis')
            ->set('name', 'Otro')
            ->call('save')
            ->assertHasErrors('code')
            ->assertSee('Ya existe un departamento con la clave SIS');

        expect(Department::count())->toBe(3);
    });

    it('edita un departamento', function () {
        $page = Livewire::actingAs($this->admin)
            ->test('academic::pages.departments')
            ->call('edit', $this->ele->id)
            ->assertSet('showModal', true)
            ->assertSet('code', 'ELE')
            ->assertSet('name', 'Eléctrica-Electrónica')
            ->set('name', 'Eléctrica')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showModal', false);

        expect($this->ele->fresh()->name)->toBe('Eléctrica')
            ->and(lastToastOf($page)['title'])->toBe('Departamento actualizado');
    });

    it('desactiva y reactiva un departamento', function () {
        $page = Livewire::actingAs($this->admin)->test('academic::pages.departments');

        $page->call('toggleActive', $this->ele->id);
        expect($this->ele->fresh()->is_active)->toBeFalse()
            ->and(lastToastOf($page)['title'])->toBe('Departamento desactivado');

        $page->call('toggleActive', $this->ele->id);
        expect($this->ele->fresh()->is_active)->toBeTrue()
            ->and(lastToastOf($page)['title'])->toBe('Departamento activado');
    });

    it('borra un departamento sin dependientes', function () {
        $page = Livewire::actingAs($this->admin)
            ->test('academic::pages.departments')
            ->call('delete', $this->ele->id);

        expect(Department::find($this->ele->id))->toBeNull()
            ->and(lastToastOf($page))->type->toBe('success')->title->toBe('Departamento borrado');
    });

    it('con dependientes no borra y muestra el error', function () {
        $page = Livewire::actingAs($this->admin)
            ->test('academic::pages.departments')
            ->call('delete', $this->sis->id);

        expect(Department::find($this->sis->id))->not->toBeNull()
            ->and(lastToastOf($page))->type->toBe('error')->description->toBe('No se puede borrar: tiene 2 carreras y 3 materias.');
    });

    it('un id que no es uuid es un departamento que no existe', function () {
        Livewire::actingAs($this->admin)
            ->test('academic::pages.departments')
            ->call('edit', 'no-es-uuid')
            ->assertNotFound();
    });

    it('carga 30 departamentos con sus conteos en pocas consultas', function () {
        Department::factory()->count(27)->create()->each(function (Department $department) {
            Career::factory()->create(['department_id' => $department->id]);
            Subject::factory()->create(['department_id' => $department->id]);
        });

        $this->actingAs($this->admin);
        DB::enableQueryLog();
        $this->get(route('academic.departments'))->assertOk();
        $queries = count(DB::getQueryLog());

        expect($queries)->toBeLessThanOrEqual(10);
    });
});

describe('otros roles e invitados', function () {
    $roles = [
        'subdirección' => [Role::Subdireccion, []],
        'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
        'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)]],
        'docente' => [Role::Docente, []],
    ];

    it('reciben 403 en la página', function (Role $role, array $scope) {
        $user = User::factory()->withRole($role, ['campus_id' => Campus::factory()->create()->id, ...$scope])->create();

        $this->actingAs($user)->get(route('academic.departments'))->assertForbidden();
    })->with($roles);

    it('reciben 403 en cada método del componente aunque lo llamen directo con una página que abrió el admin', function (string $method, bool $editFirst) {
        $user = User::factory()->withRole(Role::Coordinador, ['campus_id' => Campus::factory()->create()->id, 'career_id' => careerKey(20)])->create();
        $page = Livewire::actingAs($this->admin)->test('academic::pages.departments');
        $departments = Department::count();

        if ($editFirst) {
            $page->call('edit', $this->ele->id);
        }

        $this->actingAs($user);
        $page->call($method, ...(in_array($method, ['edit', 'toggleActive', 'delete'], true) ? [$this->ele->id] : []))->assertForbidden();

        expect(Department::count())->toBe($departments)
            ->and($this->ele->fresh()->is_active)->toBeTrue()
            ->and(Activity::where('subject_type', $this->ele->getMorphClass())->count())->toBe(0);
    })->with([
        'nuevo' => ['create', false],
        'editar' => ['edit', false],
        'guardar un alta' => ['save', false],
        'guardar una edición' => ['save', true],
        'activar o desactivar' => ['toggleActive', false],
        'borrar' => ['delete', false],
    ]);

    it('un invitado va al login', function () {
        $this->get(route('academic.departments'))->assertRedirect('/login');
    });
});

describe('menú', function () {
    it('la entrada Departamentos apunta a la página y la ve solo el admin', function () {
        $item = collect(app(Navigation::class)->items())->firstWhere('label', 'Departamentos');
        $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => Campus::factory()->create()->id, 'career_id' => careerKey(20)])->create();

        expect($item)->route->toBe('academic.departments')->icon->toBe('o-building-library')
            ->and(route('academic.departments', absolute: false))->toBe('/academic/departments')
            ->and(seesDepartmentsMenu($this->admin))->toBeTrue()
            ->and(seesDepartmentsMenu($coordinador))->toBeFalse();
    });
});
