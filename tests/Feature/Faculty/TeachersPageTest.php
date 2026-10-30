<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Faculty\Jobs\ProvisionTeacherUsersJob;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Models\TeacherPosition;
use Dom\Element;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| Docentes (`/faculty/teachers`, faculty::pages.teachers; spec 4.3 con la adenda del 30 de octubre de 2026). Los
| docentes son de todo el Tec: la adscripción es solo de un departamento. Lo ven el admin y la Subdirección (a
| todos) y los jefes de departamento y de proyecto docente (a los de su departamento). Tabla con número, nombre,
| adscripción principal y otras, horas base del periodo activo, usuario y estado; filtros de departamento, tipo de
| plaza y estado. Alta en un modal con el departamento de quien da de alta (el jefe, el suyo; el admin y la
| Subdirección lo eligen); con un número que ya existe, el admin y la Subdirección pueden adscribirlo y a los jefes
| solo se les dice que está registrado (S9). En «Acciones»: «Quitar de <código>»
| por cada departamento que cubre (RemoveAdscription) y «Desactivar/Activar» (solo admin y Subdirección); nadie se
| quita ni se da de baja a sí mismo. «Dar de alta usuarios» va en «Más acciones», es del admin y encola
| ProvisionTeacherUsersJob.
*/

beforeEach(function () {
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->ii = Department::factory()->create(['code' => 'II', 'name' => 'Ingeniería Industrial']);
    $this->cb = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $this->term = Term::factory()->current()->create(['name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-25', 'ends_on' => '2027-06-11']);

    // Principal en II y otra adscripción a SC; dos plazas vigentes.
    $this->ana = Teacher::factory()->adscribedTo($this->ii)->create(['employee_number' => '1000001', 'name' => 'Ana Pérez']);
    TeacherDepartment::factory()->for($this->ana)->for($this->sc)->create(['is_primary' => false, 'created_at' => now()->addSecond()]);
    TeacherPosition::factory()->for($this->ana)->tiempoCompleto()->create();
    TeacherPosition::factory()->for($this->ana)->asignatura(5)->create();
    $this->beto = Teacher::factory()->adscribedTo($this->cb)->create(['employee_number' => '1000002', 'name' => 'Beto Ruiz']);
    $this->carla = Teacher::factory()->adscribedTo($this->ii)->inactive()->create(['employee_number' => '1000003', 'name' => 'Carla Díaz']);
    $this->dario = Teacher::factory()->adscribedTo($this->sc)->create([
        'employee_number' => '1000004',
        'name' => 'Darío Soto',
        'user_id' => User::factory()->withRole(Role::Docente)->create(['username' => '1000004'])->id,
    ]);
    $this->sin = Teacher::factory()->create(['employee_number' => '1000005', 'name' => 'Elsa Mora']);

    $this->admin = User::factory()->admin()->create();
    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sc->id])->create();
    $this->subdireccion = User::factory()->withRole(Role::Subdireccion)->create();
});

function seesTeachersMenu(User $user): bool
{
    test()->actingAs($user);

    return collect(app(Navigation::class)->visibleTo(app(ScopedContext::class)))
        ->contains(fn (NavigationItem $item): bool => $item->label === 'Docentes');
}

/**
 * Los renglones de la tabla, por número de empleado, con su texto.
 *
 * @return array<string, string>
 */
function teacherRowsFor(User $user): array
{
    $response = test()->actingAs($user)->get(route('faculty.teachers'))->assertOk();

    return collect(domOf($response)->querySelectorAll('table tbody tr'))
        ->mapWithKeys(fn ($row): array => [(string) textOf($row->querySelector('td')) => textOf($row)])
        ->all();
}

/**
 * Los números de empleado de la tabla que ve `$user`.
 *
 * @return list<string>
 */
function teacherNumbersOnPage(User $user): array
{
    return array_map('strval', array_keys(teacherRowsFor($user)));
}

/**
 * El texto del menú «Acciones» de la fila del docente `$number`, en la página que ve `$user`.
 */
function teacherRowMenu(User $user, string $number): ?Element
{
    $dom = domOf(test()->actingAs($user)->get(route('faculty.teachers'))->assertOk());

    return collect($dom->querySelectorAll('table tbody tr'))
        ->first(fn ($row): bool => textOf($row->querySelector('td')) === $number)
        ?->querySelector('[role="menu"]');
}

describe('admin', function () {
    it('ve la tabla con número en Mono, nombre, adscripción principal y otras, horas base del periodo activo, usuario y estado', function () {
        $response = $this->actingAs($this->admin)
            ->get(route('faculty.teachers'))
            ->assertOk()
            ->assertSee('Ene-Jun 2027')
            ->assertSeeInOrder(['Número', 'Nombre', 'Adscripción', 'Horas base', 'Usuario', 'Estado']);
        $rows = teacherRowsFor($this->admin);

        expect(array_map('strval', array_keys($rows)))->toBe(['1000001', '1000002', '1000003', '1000004', '1000005'])
            ->and($rows['1000001'])->toContain('Ana Pérez', 'II', 'También SC', '45', 'No', 'Activo')->not->toContain(' en C')
            ->and($rows['1000003'])->toContain('Carla Díaz', 'II', 'Inactivo')
            ->and($rows['1000004'])->toContain('Darío Soto', 'SC', 'Sí')
            ->and($rows['1000005'])->toContain('Elsa Mora', 'Sin departamento')
            ->and(domOf($response)->querySelector('table tbody tr td span.font-mono')?->textContent)->toBe('1000001');
    });

    it('nunca dice «horas extras»: las horas son las base del periodo activo', function () {
        $main = domOf($this->actingAs($this->admin)->get(route('faculty.teachers'))->assertOk())->querySelector('main');

        expect(mb_strtolower(textOf($main)))->toContain('horas base')->not->toContain('extra');
    });

    it('filtra por departamento, tipo de plaza y estado, sin filtro de campus', function () {
        $dom = domOf($this->actingAs($this->admin)->get(route('faculty.teachers')));

        expect(collect($dom->querySelectorAll('[data-testid="catalog-toolbar"] select'))->map(fn ($select): string => $select->getAttribute('aria-label'))->all())
            ->toBe(['Departamento', 'Tipo de plaza', 'Estado']);

        Livewire::actingAs($this->admin)
            ->test('faculty::pages.teachers')
            ->set('search', 'beto')
            ->assertSee('Beto Ruiz')->assertDontSee('Ana Pérez')
            ->set('search', '')
            ->set('departmentFilter', $this->ii->id)
            ->assertSee('Ana Pérez')->assertSee('Carla Díaz')->assertDontSee('Darío Soto')
            ->set('departmentFilter', '')
            ->set('kindFilter', 'tiempo_completo')
            ->assertSee('Ana Pérez')->assertDontSee('Beto Ruiz')
            ->set('kindFilter', '')
            ->set('status', 'inactive')
            ->assertSee('Carla Díaz')->assertDontSee('Ana Pérez');
    });

    it('da de alta un docente eligiendo el departamento; queda con usuario', function () {
        $page = Livewire::actingAs($this->admin)
            ->test('faculty::pages.teachers')
            ->call('create')
            ->assertSet('showModal', true)
            ->set('employee_number', ' 2000001 ')
            ->set('name', 'Elena Vega')
            ->set('email', 'elena@example.test')
            ->set('degree', 'doctorado')
            ->set('hired_on', '2019-08-19')
            ->set('department_id', $this->cb->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showModal', false);

        $teacher = Teacher::where('employee_number', '2000001')->sole();

        expect($teacher->adscriptions()->sole())
            ->department_id->toBe($this->cb->id)
            ->is_primary->toBeTrue()
            ->and($teacher->user?->username)->toBe('2000001')
            ->and(lastToastOf($page))->type->toBe('success')->title->toBe('Docente creado')->description->toBe('2000001 · Elena Vega');
    });

    it('muestra los errores en español junto a su campo', function () {
        Livewire::actingAs($this->admin)
            ->test('faculty::pages.teachers')
            ->call('create')
            ->set('email', 'no-es-correo')
            ->call('save')
            ->assertHasErrors(['employee_number' => 'required', 'name' => 'required', 'email' => 'email', 'department_id' => 'required'])
            ->assertSee('El campo número de empleado es obligatorio.')
            ->assertSee('El campo departamento es obligatorio.');

        expect(Teacher::count())->toBe(5);
    });

    it('el select de departamento marca los inactivos', function () {
        $this->ii->update(['is_active' => false]);

        Livewire::actingAs($this->admin)
            ->test('faculty::pages.teachers')
            ->call('create')
            ->assertSee('II · Ingeniería Industrial (inactivo)');
    });

    it('un número que ya existe ofrece «Adscribir a <depto>» y adscribe sin cambiar sus datos (S9)', function () {
        $page = Livewire::actingAs($this->admin)
            ->test('faculty::pages.teachers')
            ->call('create')
            ->set('department_id', $this->sc->id)
            ->set('employee_number', '100 0002')
            ->assertSee('Ya existe el docente')
            ->assertSee('Adscribir a SC')
            ->call('adscribeExisting')
            ->assertHasNoErrors()
            ->assertSet('showModal', false);

        expect($this->beto->fresh()->name)->toBe('Beto Ruiz')
            ->and($this->beto->adscriptions()->pluck('department_id')->sort()->values()->all())->toBe(collect([$this->cb->id, $this->sc->id])->sort()->values()->all())
            ->and($this->beto->adscriptions()->where('department_id', $this->sc->id)->sole()->is_primary)->toBeFalse()
            ->and(lastToastOf($page))->type->toBe('success')->title->toBe('Docente adscrito a SC')->description->toBe('1000002 · Beto Ruiz');
    });

    it('guardar con un número que ya existe no da de alta otro: ofrece adscribirlo', function () {
        Livewire::actingAs($this->admin)
            ->test('faculty::pages.teachers')
            ->call('create')
            ->set('department_id', $this->sc->id)
            ->set('name', 'Otro nombre')
            ->set('employee_number', '1000002')
            ->call('save')
            ->assertSet('showModal', true)
            ->assertSee('Adscribir a SC');

        expect(Teacher::count())->toBe(5)
            ->and($this->beto->fresh()->name)->toBe('Beto Ruiz');
    });

    it('si ya está adscrito ahí, lo dice y no escribe', function () {
        Livewire::actingAs($this->admin)
            ->test('faculty::pages.teachers')
            ->call('create')
            ->set('department_id', $this->cb->id)
            ->set('employee_number', '1000002')
            ->call('adscribeExisting')
            ->assertHasErrors('adscription')
            ->assertSee('Beto Ruiz ya está adscrito a CB.');

        expect(TeacherDepartment::where('teacher_id', $this->beto->id)->count())->toBe(1);
    });

    it('al número de un docente dado de baja no le ofrece adscribirlo: pide reactivarlo primero', function () {
        Livewire::actingAs($this->admin)
            ->test('faculty::pages.teachers')
            ->call('create')
            ->set('department_id', $this->sc->id)
            ->set('employee_number', '1000003')
            ->assertHasErrors('employee_number')
            ->assertSee('Ese número es de un docente dado de baja: reactívalo desde la lista antes de adscribirlo.')
            ->assertDontSee('Adscribir a SC');
    });

    it('si el docente se dio de baja o se borró mientras tanto, «Adscribir» lo dice en el número y no escribe', function (string $change) {
        $page = Livewire::actingAs($this->admin)
            ->test('faculty::pages.teachers')
            ->call('create')
            ->set('department_id', $this->sc->id)
            ->set('employee_number', '1000002')
            ->assertSee('Adscribir a SC');

        $change === 'baja' ? $this->beto->update(['is_active' => false]) : $this->beto->delete();

        $page->call('adscribeExisting')
            ->assertOk()
            ->assertHasErrors('employee_number')
            ->assertSee($change === 'baja'
                ? 'Ese número es de un docente dado de baja: reactívalo desde la lista antes de adscribirlo.'
                : 'Ese número pertenece a un docente borrado; pide a Subdirección que lo revise.');

        expect(TeacherDepartment::withTrashed()->where('teacher_id', $this->beto->id)->count())->toBe(1);
    })->with(['dado de baja' => 'baja', 'borrado' => 'borrado']);

    it('el número de un docente borrado lo dice en el formulario, sin callejón sin salida', function () {
        $this->beto->delete();

        Livewire::actingAs($this->admin)
            ->test('faculty::pages.teachers')
            ->call('create')
            ->set('department_id', $this->sc->id)
            ->set('name', 'Otro')
            ->set('employee_number', '1000002')
            ->assertHasErrors('employee_number')
            ->assertSee('Ese número pertenece a un docente borrado; pide a Subdirección que lo revise.')
            ->call('save')
            ->assertHasErrors('employee_number')
            ->assertSet('showModal', true);

        expect(Teacher::withTrashed()->where('employee_number', '1000002')->count())->toBe(1);
    });

    it('edita los datos de un docente sin cambiar su número', function () {
        $page = Livewire::actingAs($this->admin)
            ->test('faculty::pages.teachers')
            ->call('edit', $this->ana->id)
            ->assertSet('employee_number', '1000001')
            ->assertSet('name', 'Ana Pérez')
            ->set('name', 'Ana Pérez Soto')
            ->set('employee_number', '9999999')
            ->call('save')
            ->assertHasNoErrors();

        expect($this->ana->fresh())->name->toBe('Ana Pérez Soto')->employee_number->toBe('1000001')
            ->and(lastToastOf($page))->title->toBe('Docente actualizado');
    });

    it('desactiva y activa desde «Acciones»; la baja desactiva su usuario', function () {
        $page = Livewire::actingAs($this->admin)->test('faculty::pages.teachers')->call('toggleActive', $this->dario->id);

        expect($this->dario->fresh()->is_active)->toBeFalse()
            ->and($this->dario->fresh()->user->is_active)->toBeFalse()
            ->and(lastToastOf($page))->title->toBe('Docente desactivado')->description->toBe('1000004 · Darío Soto');

        $page->call('toggleActive', $this->dario->id);

        expect($this->dario->fresh()->is_active)->toBeTrue()
            ->and($this->dario->fresh()->user->is_active)->toBeTrue()
            ->and(lastToastOf($page))->title->toBe('Docente activado');
    });

    it('«Acciones» de cada fila ofrece «Quitar de <código>» por cada departamento que cubre y «Desactivar» o «Activar»', function () {
        expect(textOf(teacherRowMenu($this->admin, '1000001')))->toContain('Quitar de II', 'Quitar de SC', 'Desactivar')
            ->and(textOf(teacherRowMenu($this->admin, '1000003')))->toContain('Activar')
            // Sin departamento no hay nada que quitar.
            ->and(textOf(teacherRowMenu($this->admin, '1000005')))->not->toContain('Quitar');
    });

    it('en la fila de su propio docente, «Quitar de <código>» y «Desactivar» salen deshabilitados con su motivo', function (string $viewer) {
        $user = $this->{$viewer};
        $own = Teacher::factory()->adscribedTo($this->sc)->create(['employee_number' => '1000009', 'user_id' => $user->id]);
        $items = collect(teacherRowMenu($user, '1000009')->querySelectorAll('[role="menuitem"]'));
        $remove = $items->first(fn ($item): bool => str_starts_with(textOf($item), 'Quitar de SC'));
        $deactivate = $items->first(fn ($item): bool => str_starts_with(textOf($item), 'Desactivar'));

        expect($remove->getAttribute('aria-disabled'))->toBe('true')
            ->and(textOf($remove))->toBe('Quitar de SC No te puedes quitar a ti mismo de un departamento.')
            ->and($remove->hasAttribute('wire:click'))->toBeFalse()
            ->and($deactivate->getAttribute('aria-disabled'))->toBe('true')
            ->and(textOf($deactivate))->toBe('Desactivar No te puedes dar de baja a ti mismo.')
            ->and($deactivate->hasAttribute('wire:click'))->toBeFalse()
            ->and($own->adscriptions()->count())->toBe(1);
    })->with(['admin' => 'admin', 'jefe de SC' => 'jefe']);

    it('quitar de un departamento la principal pasa la principal a la más antigua que le queda', function () {
        $page = Livewire::actingAs($this->admin)
            ->test('faculty::pages.teachers')
            ->call('removeAdscription', $this->ana->adscriptions()->where('department_id', $this->ii->id)->sole()->id);

        expect($this->ana->adscriptions()->sole())->department_id->toBe($this->sc->id)->is_primary->toBeTrue()
            ->and($this->ana->fresh()->is_active)->toBeTrue()
            ->and(lastToastOf($page))->title->toBe('Docente quitado de II')->description->toBe('1000001 · Ana Pérez; su adscripción principal pasa a SC');
    });

    it('el aviso de quitar la principal dice qué principal queda', function () {
        $menu = teacherRowMenu($this->admin, '1000001');
        $item = collect($menu->querySelectorAll('[role="menuitem"]'))->first(fn ($item): bool => str_starts_with(textOf($item), 'Quitar de II'));

        expect($item->getAttribute('wire:confirm'))->toContain('su adscripción principal pasa a SC');
    });

    it('«Más acciones» junto a «Nuevo docente» lleva «Dar de alta usuarios»', function () {
        $toolbar = domOf($this->actingAs($this->admin)->get(route('faculty.teachers')))->querySelector('[data-testid="catalog-toolbar"]');
        $more = $toolbar->querySelector('button[aria-label="Más acciones"][aria-haspopup="menu"]');

        expect($toolbar->querySelectorAll('.btn-primary'))->toHaveCount(1)
            ->and($more)->not->toBeNull()
            ->and(textOf($toolbar->querySelector('[role="menu"] [role="menuitem"]')))->toBe('Dar de alta usuarios')
            ->and($toolbar->querySelector('[role="menu"] [role="menuitem"]')->hasAttribute('wire:click'))->toBeTrue();
    });

    it('«Dar de alta usuarios» encola ProvisionTeacherUsersJob a su nombre y avisa', function () {
        Queue::fake();

        $page = Livewire::actingAs($this->admin)->test('faculty::pages.teachers')->call('provisionUsers');

        Queue::assertPushed(ProvisionTeacherUsersJob::class, fn (ProvisionTeacherUsersJob $job): bool => $job->actor?->is($this->admin) === true);
        expect(lastToastOf($page))->type->toBe('success')->title->toBe('Alta de usuarios en cola');
    });

    it('sin NEXUS_DEFAULT_PASSWORD, «Dar de alta usuarios» avisa con el motivo y no encola nada', function () {
        Queue::fake();
        config(['auth.default_password' => null]);

        $page = Livewire::actingAs($this->admin)->test('faculty::pages.teachers')->call('provisionUsers');

        Queue::assertNothingPushed();
        expect(lastToastOf($page))->type->toBe('error')->description->toContain('NEXUS_DEFAULT_PASSWORD');
    });

    it('sin NEXUS_DEFAULT_PASSWORD, el alta avisa y no deja nada a medias', function () {
        config(['auth.default_password' => null]);

        $page = Livewire::actingAs($this->admin)
            ->test('faculty::pages.teachers')
            ->call('create')
            ->set('employee_number', '2000002')
            ->set('name', 'Fernanda Luna')
            ->set('department_id', $this->sc->id)
            ->call('save');

        expect(Teacher::where('employee_number', '2000002')->exists())->toBeFalse()
            ->and(lastToastOf($page))->type->toBe('error')->description->toContain('NEXUS_DEFAULT_PASSWORD');
    });

    it('un id que no es uuid es un docente que no existe', function () {
        Livewire::actingAs($this->admin)->test('faculty::pages.teachers')->call('edit', 'no-es-uuid')->assertNotFound();
    });

    it('carga 40 docentes con 3 adscripciones cada uno en pocas consultas', function () {
        $departments = [$this->sc, $this->ii, $this->cb];

        Teacher::factory()->count(40)->create()->each(function (Teacher $teacher) use ($departments): void {
            foreach ([0, 1, 2] as $offset) {
                TeacherDepartment::factory()->for($teacher)->create(['department_id' => $departments[$offset]->id, 'is_primary' => $offset === 0]);
            }

            TeacherPosition::factory()->for($teacher)->medioTiempo()->create();
        });

        $this->actingAs($this->admin);
        DB::enableQueryLog();
        $this->get(route('faculty.teachers'))->assertOk();

        expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(16);
    });
});

describe('Subdirección, una para todo el Tec', function () {
    it('ve a todos los docentes, también sin departamento', function () {
        expect(teacherNumbersOnPage($this->subdireccion))->toBe(['1000001', '1000002', '1000003', '1000004', '1000005']);
    });

    it('elige el departamento del alta', function () {
        Livewire::actingAs($this->subdireccion)
            ->test('faculty::pages.teachers')
            ->call('create')
            ->assertSet('department_id', '')
            ->set('employee_number', '2000005')
            ->set('name', 'Irene Paz')
            ->set('department_id', $this->cb->id)
            ->call('save')
            ->assertHasNoErrors();

        expect(Teacher::where('employee_number', '2000005')->sole()->adscriptions()->sole()->department_id)->toBe($this->cb->id);
    });

    it('adscribe a un departamento a un docente que ya existe, sin cambiar sus datos (S9)', function () {
        $page = Livewire::actingAs($this->subdireccion)
            ->test('faculty::pages.teachers')
            ->call('create')
            ->set('department_id', $this->sc->id)
            ->set('employee_number', '1000002')
            ->assertSee('Ya existe el docente 1000002 · Beto Ruiz')
            ->call('adscribeExisting')
            ->assertHasNoErrors();

        expect($this->beto->adscriptions()->where('department_id', $this->sc->id)->exists())->toBeTrue()
            ->and(lastToastOf($page))->title->toBe('Docente adscrito a SC');
    });

    it('da de baja y ve «Dar de alta usuarios» deshabilitado con su motivo', function () {
        Livewire::actingAs($this->subdireccion)->test('faculty::pages.teachers')->call('toggleActive', $this->beto->id);

        $item = domOf($this->actingAs($this->subdireccion)->get(route('faculty.teachers')))
            ->querySelector('[data-testid="catalog-toolbar"] [role="menu"] [role="menuitem"]');

        expect($this->beto->fresh()->is_active)->toBeFalse()
            ->and($item->getAttribute('aria-disabled'))->toBe('true')
            ->and(textOf($item))->toBe('Dar de alta usuarios Solo el administrador da de alta usuarios.');
    });
});

describe('jefe de departamento de SC, de todo el Tec', function () {
    it('solo ve a los docentes adscritos a SC, aunque su principal sea otra', function () {
        expect(teacherNumbersOnPage($this->jefe))->toBe(['1000001', '1000004']);
    });

    it('da de alta con su departamento, sin elegirlo, y ve al nuevo en su lista', function () {
        $page = Livewire::actingAs($this->jefe)
            ->test('faculty::pages.teachers')
            ->call('create')
            ->assertSet('department_id', $this->sc->id)
            ->set('employee_number', '2000003')
            ->set('name', 'Gabriel Mora')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Gabriel Mora');

        expect(Teacher::where('employee_number', '2000003')->sole()->adscriptions()->sole()->department_id)->toBe($this->sc->id)
            ->and(lastToastOf($page))->title->toBe('Docente creado');
    });

    it('no puede dar de alta en otro departamento aunque lo mande el navegador', function () {
        Livewire::actingAs($this->jefe)
            ->test('faculty::pages.teachers')
            ->call('create')
            ->set('employee_number', '2000004')
            ->set('name', 'Hugo León')
            ->set('department_id', $this->ii->id)
            ->call('save')
            ->assertHasErrors('department_id');

        expect(Teacher::where('employee_number', '2000004')->exists())->toBeFalse();
    });

    it('con el número de un docente que ya existe solo le dice que está registrado, sin decir de quién (S9, supuesto por confirmar)', function () {
        Livewire::actingAs($this->jefe)
            ->test('faculty::pages.teachers')
            ->call('create')
            ->set('name', 'Otro nombre')
            ->set('employee_number', '1000002')
            ->assertHasErrors('employee_number')
            ->assertSee('Ese número ya está registrado. Pide a Subdirección que lo adscriba a tu departamento.')
            ->assertDontSee('Beto Ruiz')
            ->assertDontSee('Adscribir a')
            ->call('save')
            ->assertHasErrors('employee_number')
            ->assertDontSee('Beto Ruiz');

        expect(Teacher::count())->toBe(5)
            ->and($this->beto->adscriptions()->count())->toBe(1);
    });

    it('recibe 403 al adscribir a un docente que ya existe, aunque lo llame directo sobre una página que abrió el admin', function () {
        $page = Livewire::actingAs($this->admin)
            ->test('faculty::pages.teachers')
            ->call('create')
            ->set('department_id', $this->sc->id)
            ->set('employee_number', '1000002')
            ->assertSee('Adscribir a SC');

        $this->actingAs($this->jefe);
        $page->call('adscribeExisting')->assertForbidden();

        expect($this->beto->adscriptions()->count())->toBe(1);
    });

    it('en «Acciones» solo ofrece «Quitar de SC»; «Desactivar» queda deshabilitado con su motivo', function () {
        $menu = teacherRowMenu($this->jefe, '1000001');
        $deactivate = collect($menu->querySelectorAll('[role="menuitem"]'))->first(fn ($item): bool => str_starts_with(textOf($item), 'Desactivar'));

        expect(textOf($menu))->toContain('Quitar de SC')->not->toContain('Quitar de II')
            ->and($deactivate->getAttribute('aria-disabled'))->toBe('true')
            ->and(textOf($deactivate))->toBe('Desactivar Solo el administrador y la Subdirección dan de baja a un docente.')
            ->and($deactivate->hasAttribute('wire:click'))->toBeFalse();
    });

    it('quita a un docente de su departamento; el docente sigue activo y sale de su lista', function () {
        $page = Livewire::actingAs($this->jefe)
            ->test('faculty::pages.teachers')
            ->call('removeAdscription', $this->dario->adscriptions()->sole()->id)
            ->assertDontSee('Darío Soto');

        expect($this->dario->adscriptions()->count())->toBe(0)
            ->and($this->dario->fresh()->is_active)->toBeTrue()
            ->and($this->dario->fresh()->user->is_active)->toBeTrue()
            ->and(lastToastOf($page))->title->toBe('Docente quitado de SC')->description->toBe('1000004 · Darío Soto; queda sin departamento');
    });

    it('recibe 403 al dar de baja, aun a uno de su departamento', function () {
        Livewire::actingAs($this->jefe)->test('faculty::pages.teachers')->call('toggleActive', $this->dario->id)->assertForbidden();

        expect($this->dario->fresh()->is_active)->toBeTrue();
    });

    it('recibe 403 al quitar de otro departamento', function () {
        $other = $this->ana->adscriptions()->where('department_id', $this->ii->id)->sole();

        Livewire::actingAs($this->jefe)->test('faculty::pages.teachers')->call('removeAdscription', $other->id)->assertForbidden();

        expect($other->fresh()->trashed())->toBeFalse();
    });

    it('no se quita a sí mismo de su departamento', function () {
        $own = Teacher::factory()->adscribedTo($this->sc)->create(['user_id' => $this->jefe->id]);

        Livewire::actingAs($this->jefe)->test('faculty::pages.teachers')->call('removeAdscription', $own->adscriptions()->sole()->id)->assertForbidden();

        expect($own->adscriptions()->count())->toBe(1);
    });

    it('recibe 403 en cada método sobre un docente que no cubre, aunque lo llame directo', function (string $method, string $teacher) {
        $target = $this->{$teacher};

        Livewire::actingAs($this->jefe)->test('faculty::pages.teachers')->call($method, $target->id)->assertForbidden();

        expect($target->fresh()->is_active)->toBe($target->is_active)
            ->and(Activity::count())->toBe(0);
    })->with([
        'editar uno de CB' => ['edit', 'beto'],
        'editar uno sin departamento' => ['edit', 'sin'],
        'activar uno de II' => ['toggleActive', 'carla'],
    ]);

    it('recibe 403 al guardar la edición de un docente ajeno que abrió el admin', function () {
        $page = Livewire::actingAs($this->admin)->test('faculty::pages.teachers')->call('edit', $this->beto->id)->set('name', 'Cambiado');

        $this->actingAs($this->jefe);
        $page->call('save')->assertForbidden();

        expect($this->beto->fresh()->name)->toBe('Beto Ruiz');
    });

    it('recibe 403 al dar de alta usuarios', function () {
        Queue::fake();

        Livewire::actingAs($this->jefe)->test('faculty::pages.teachers')->call('provisionUsers')->assertForbidden();

        Queue::assertNothingPushed();
    });
});

describe('jefe de proyecto docente de SC en C2', function () {
    beforeEach(function () {
        $this->jpd = User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->c2->id, 'department_id' => $this->sc->id])->create();
    });

    it('ve a los mismos docentes que su jefe: el docente no es de un campus', function () {
        expect(teacherNumbersOnPage($this->jpd))->toBe(['1000001', '1000004']);
    });

    it('quita a un docente de SC; la principal en II no cambia', function () {
        $page = Livewire::actingAs($this->jpd)
            ->test('faculty::pages.teachers')
            ->call('removeAdscription', $this->ana->adscriptions()->where('department_id', $this->sc->id)->sole()->id);

        expect($this->ana->adscriptions()->sole())->department_id->toBe($this->ii->id)->is_primary->toBeTrue()
            ->and(lastToastOf($page))->title->toBe('Docente quitado de SC')->description->toBe('1000001 · Ana Pérez');
    });

    it('recibe 403 al quitar a un docente de otro departamento', function () {
        $other = $this->ana->adscriptions()->where('department_id', $this->ii->id)->sole();

        Livewire::actingAs($this->jpd)->test('faculty::pages.teachers')->call('removeAdscription', $other->id)->assertForbidden();

        expect($other->fresh()->trashed())->toBeFalse();
    });
});

describe('otros roles e invitados', function () {
    $roles = [
        'coordinador' => [Role::Coordinador, fn () => ['campus_id' => Campus::factory()->create()->id, 'career_id' => careerKey(20)]],
        'docente' => [Role::Docente, []],
    ];

    it('reciben 403 en la página', function (Role $role, array $scope) {
        $user = User::factory()->withRole($role, $scope)->create();

        $this->actingAs($user)->get(route('faculty.teachers'))->assertForbidden();
    })->with($roles);

    it('reciben 403 en cada método del componente aunque lo llamen directo con una página que abrió el admin', function (string $method, bool $editFirst) {
        Queue::fake();
        $user = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])->create();
        $page = Livewire::actingAs($this->admin)->test('faculty::pages.teachers');
        $teachers = Teacher::count();
        $adscriptions = TeacherDepartment::count();

        if ($editFirst) {
            $page->call('edit', $this->ana->id)->set('name', 'Cambiado');
        } else {
            $page->call('create')->set('employee_number', '1000002')->set('department_id', $this->sc->id);
        }

        $this->actingAs($user);
        $argument = match ($method) {
            'edit', 'toggleActive' => [$this->ana->id],
            'removeAdscription' => [$this->ana->adscriptions()->first()->id],
            default => [],
        };
        $page->call($method, ...$argument)->assertForbidden();

        expect(Teacher::count())->toBe($teachers)
            ->and(TeacherDepartment::count())->toBe($adscriptions)
            ->and($this->ana->fresh())->name->toBe('Ana Pérez')->is_active->toBeTrue()
            ->and(Activity::count())->toBe(0);
        Queue::assertNothingPushed();
    })->with([
        'nuevo' => ['create', false],
        'editar' => ['edit', false],
        'guardar un alta' => ['save', false],
        'guardar una edición' => ['save', true],
        'adscribir uno que ya existe' => ['adscribeExisting', false],
        'activar o desactivar' => ['toggleActive', false],
        'quitar de un departamento' => ['removeAdscription', false],
        'dar de alta usuarios' => ['provisionUsers', false],
    ]);

    it('un invitado va al login', function () {
        $this->get(route('faculty.teachers'))->assertRedirect('/login');
    });
});

describe('menú', function () {
    it('la entrada Docentes, en Recursos humanos, la ven el admin, la Subdirección, los jefes y los JPD, y no el coordinador ni el docente', function () {
        $item = collect(app(Navigation::class)->items())->firstWhere('label', 'Docentes');
        $jpd = User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->c2->id, 'department_id' => $this->sc->id])->create();
        $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])->create();
        $docente = User::factory()->withRole(Role::Docente)->create();

        expect($item)->route->toBe('faculty.teachers')->icon->toBe('o-users')->area->toBe(NavigationArea::RecursosHumanos)
            ->and(route('faculty.teachers', absolute: false))->toBe('/faculty/teachers')
            ->and(seesTeachersMenu($this->admin))->toBeTrue()
            ->and(seesTeachersMenu($this->subdireccion))->toBeTrue()
            ->and(seesTeachersMenu($this->jefe))->toBeTrue()
            ->and(seesTeachersMenu($jpd))->toBeTrue()
            ->and(seesTeachersMenu($coordinador))->toBeFalse()
            ->and(seesTeachersMenu($docente))->toBeFalse();
    });
});
