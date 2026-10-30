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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| Docentes (`/faculty/teachers`, faculty::pages.teachers; spec 4.3). Lo ven el admin, la subdirección, los jefes de
| departamento y los jefes de proyecto docente, cada quien los docentes de su alcance (TeacherPolicy). Tabla con
| número, nombre, adscripción principal y otras, horas base del periodo activo, usuario y estado; alta en un modal
| con la adscripción de quien da de alta (el jefe, la suya; la subdirección elige el departamento; el admin, también
| el campus); un número que ya existe ofrece adscribirlo (S9); «Editar», y activar o desactivar en «Acciones».
| «Dar de alta usuarios» es del admin y encola ProvisionTeacherUsersJob.
*/

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->ii = Department::factory()->create(['code' => 'II', 'name' => 'Ingeniería Industrial']);
    $this->cb = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $this->term = Term::factory()->current()->create(['name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-25', 'ends_on' => '2027-06-11']);

    // Principal en II de C2 y otra adscripción a SC de C2; dos plazas vigentes.
    $this->ana = Teacher::factory()->adscribedTo($this->ii, $this->c2)->adscribedTo($this->sc, $this->c2, primary: false)
        ->create(['employee_number' => '1000001', 'name' => 'Ana Pérez']);
    TeacherPosition::factory()->for($this->ana)->tiempoCompleto()->create();
    TeacherPosition::factory()->for($this->ana)->asignatura(5)->create();
    $this->beto = Teacher::factory()->adscribedTo($this->sc, $this->c1)->create(['employee_number' => '1000002', 'name' => 'Beto Ruiz']);
    $this->carla = Teacher::factory()->adscribedTo($this->ii, $this->c2)->inactive()->create(['employee_number' => '1000003', 'name' => 'Carla Díaz']);
    $this->dario = Teacher::factory()->adscribedTo($this->sc, $this->c2)->create([
        'employee_number' => '1000004',
        'name' => 'Darío Soto',
        'user_id' => User::factory()->withRole(Role::Docente, ['campus_id' => $this->c2->id])->create(['username' => '1000004'])->id,
    ]);

    $this->admin = User::factory()->admin()->create();
    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->c2->id, 'department_id' => $this->sc->id])->create();
    $this->subdireccion = User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->c2->id])->create();
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
        ->mapWithKeys(fn ($row): array => [textOf($row->querySelector('td')) => textOf($row)])
        ->all();
}

describe('admin', function () {
    it('ve la tabla con número en Mono, nombre, adscripción principal y otras, horas base del periodo activo, usuario y estado', function () {
        $response = $this->actingAs($this->admin)
            ->get(route('faculty.teachers'))
            ->assertOk()
            ->assertSee('Ene-Jun 2027')
            ->assertSeeInOrder(['Número', 'Nombre', 'Adscripción', 'Horas base', 'Usuario', 'Estado']);
        $rows = teacherRowsFor($this->admin);

        expect(array_map('strval', array_keys($rows)))->toBe(['1000001', '1000002', '1000003', '1000004'])
            ->and($rows['1000001'])->toContain('Ana Pérez', 'II en C2', 'También SC en C2', '45', 'No', 'Activo')
            ->and($rows['1000003'])->toContain('Carla Díaz', 'II en C2', 'Inactivo')
            ->and($rows['1000004'])->toContain('Darío Soto', 'SC en C2', 'Sí')
            ->and(domOf($response)->querySelector('table tbody tr td span.font-mono')?->textContent)->toBe('1000001');
    });

    it('nunca dice «horas extras»: las horas son las base del periodo activo', function () {
        $main = domOf($this->actingAs($this->admin)->get(route('faculty.teachers'))->assertOk())->querySelector('main');

        expect(mb_strtolower(textOf($main)))->toContain('horas base')->not->toContain('extra');
    });

    it('busca y filtra por departamento, campus, tipo de plaza y estado', function () {
        Livewire::actingAs($this->admin)
            ->test('faculty::pages.teachers')
            ->set('search', 'beto')
            ->assertSee('Beto Ruiz')->assertDontSee('Ana Pérez')
            ->set('search', '')
            ->set('campusFilter', $this->c1->id)
            ->assertSee('Beto Ruiz')->assertDontSee('Darío Soto')
            ->set('campusFilter', '')
            ->set('departmentFilter', $this->ii->id)
            ->assertSee('Ana Pérez')->assertSee('Carla Díaz')->assertDontSee('Darío Soto')
            ->set('departmentFilter', '')
            ->set('kindFilter', 'tiempo_completo')
            ->assertSee('Ana Pérez')->assertDontSee('Beto Ruiz')
            ->set('kindFilter', '')
            ->set('status', 'inactive')
            ->assertSee('Carla Díaz')->assertDontSee('Ana Pérez');
    });

    it('da de alta un docente eligiendo departamento y campus; queda con usuario', function () {
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
            ->set('campus_id', $this->c1->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showModal', false);

        $teacher = Teacher::where('employee_number', '2000001')->sole();

        expect($teacher->adscriptions()->sole())
            ->department_id->toBe($this->cb->id)
            ->campus_id->toBe($this->c1->id)
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
            ->assertHasErrors(['employee_number' => 'required', 'name' => 'required', 'email' => 'email', 'department_id' => 'required', 'campus_id' => 'required'])
            ->assertSee('El campo número de empleado es obligatorio.')
            ->assertSee('El campo departamento es obligatorio.');

        expect(Teacher::count())->toBe(4);
    });

    it('un número que ya existe ofrece «Adscribir a <depto> en <campus>» y adscribe sin cambiar sus datos (S9)', function () {
        $page = Livewire::actingAs($this->admin)
            ->test('faculty::pages.teachers')
            ->call('create')
            ->set('department_id', $this->cb->id)
            ->set('campus_id', $this->c1->id)
            ->set('employee_number', '100 0002')
            ->assertSee('Ya existe el docente')
            ->assertSee('Beto Ruiz')
            ->assertSee('Adscribir a CB en C1')
            ->call('adscribeExisting')
            ->assertHasNoErrors()
            ->assertSet('showModal', false);

        expect($this->beto->fresh()->name)->toBe('Beto Ruiz')
            ->and($this->beto->adscriptions()->pluck('department_id')->sort()->values()->all())->toBe(collect([$this->sc->id, $this->cb->id])->sort()->values()->all())
            ->and($this->beto->adscriptions()->where('department_id', $this->cb->id)->sole()->is_primary)->toBeFalse()
            ->and(lastToastOf($page))->type->toBe('success')->title->toBe('Docente adscrito a CB en C1')->description->toBe('1000002 · Beto Ruiz');
    });

    it('guardar con un número que ya existe no da de alta otro: ofrece adscribirlo', function () {
        Livewire::actingAs($this->admin)
            ->test('faculty::pages.teachers')
            ->call('create')
            ->set('department_id', $this->cb->id)
            ->set('campus_id', $this->c2->id)
            ->set('name', 'Otro nombre')
            ->set('employee_number', '1000002')
            ->call('save')
            ->assertSet('showModal', true)
            ->assertSee('Adscribir a CB en C2');

        expect(Teacher::count())->toBe(4)
            ->and($this->beto->fresh()->name)->toBe('Beto Ruiz');
    });

    it('si ya está adscrito ahí, lo dice y no escribe', function () {
        Livewire::actingAs($this->admin)
            ->test('faculty::pages.teachers')
            ->call('create')
            ->set('department_id', $this->sc->id)
            ->set('campus_id', $this->c1->id)
            ->set('employee_number', '1000002')
            ->call('adscribeExisting')
            ->assertHasErrors('adscription')
            ->assertSee('Beto Ruiz ya está adscrito a SC en C1.');

        expect(TeacherDepartment::where('teacher_id', $this->beto->id)->count())->toBe(1);
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

    it('el menú «Acciones» de cada fila ofrece Desactivar o Activar', function () {
        $dom = domOf($this->actingAs($this->admin)->get(route('faculty.teachers')));
        $menuOf = fn (string $number): string => textOf(collect($dom->querySelectorAll('table tbody tr'))
            ->first(fn ($row): bool => str_contains(textOf($row), $number))
            ?->querySelector('[role="menu"]'));

        expect($menuOf('1000001'))->toContain('Desactivar')
            ->and($menuOf('1000003'))->toContain('Activar');
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
            ->set('campus_id', $this->c2->id)
            ->call('save');

        expect(Teacher::where('employee_number', '2000002')->exists())->toBeFalse()
            ->and(lastToastOf($page))->type->toBe('error')->description->toContain('NEXUS_DEFAULT_PASSWORD');
    });

    it('un id que no es uuid es un docente que no existe', function () {
        Livewire::actingAs($this->admin)->test('faculty::pages.teachers')->call('edit', 'no-es-uuid')->assertNotFound();
    });

    it('carga 40 docentes con 3 adscripciones cada uno en pocas consultas', function () {
        $campuses = [$this->c1, $this->c2, Campus::factory()->create()];
        $departments = [$this->sc, $this->ii, $this->cb];

        Teacher::factory()->count(40)->create()->each(function (Teacher $teacher, int $index) use ($campuses, $departments): void {
            foreach ([0, 1, 2] as $offset) {
                TeacherDepartment::factory()->for($teacher)->create([
                    'department_id' => $departments[($index + $offset) % 3]->id,
                    'campus_id' => $campuses[$offset]->id,
                    'is_primary' => $offset === 0,
                ]);
            }

            TeacherPosition::factory()->for($teacher)->medioTiempo()->create();
        });

        $this->actingAs($this->admin);
        DB::enableQueryLog();
        $this->get(route('faculty.teachers'))->assertOk();

        expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(16);
    });
});

describe('jefe de departamento de SC en C2', function () {
    it('solo ve a los docentes adscritos a SC en C2, aunque su principal sea otra', function () {
        expect(array_map('strval', array_keys(teacherRowsFor($this->jefe))))->toBe(['1000001', '1000004']);
    });

    it('da de alta con su departamento y su campus, sin elegirlos, y ve al nuevo en su lista', function () {
        $page = Livewire::actingAs($this->jefe)
            ->test('faculty::pages.teachers')
            ->call('create')
            ->assertSet('department_id', $this->sc->id)
            ->assertSet('campus_id', $this->c2->id)
            ->set('employee_number', '2000003')
            ->set('name', 'Gabriel Mora')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Gabriel Mora');

        expect(Teacher::where('employee_number', '2000003')->sole()->adscriptions()->sole())
            ->department_id->toBe($this->sc->id)
            ->campus_id->toBe($this->c2->id)
            ->and(lastToastOf($page))->title->toBe('Docente creado');
    });

    it('no puede dar de alta en otro departamento ni en otro campus aunque lo mande el navegador', function (string $field, string $other) {
        Livewire::actingAs($this->jefe)
            ->test('faculty::pages.teachers')
            ->call('create')
            ->set('employee_number', '2000004')
            ->set('name', 'Hugo León')
            ->set($field, $this->{$other}->id)
            ->call('save')
            ->assertHasErrors('department_id');

        expect(Teacher::where('employee_number', '2000004')->exists())->toBeFalse();
    })->with([
        'otro departamento' => ['department_id', 'ii'],
        'otro campus' => ['campus_id', 'c1'],
    ]);

    it('adscribe a su departamento a un docente de otro departamento que ya existe (S9)', function () {
        $page = Livewire::actingAs($this->jefe)
            ->test('faculty::pages.teachers')
            ->call('create')
            ->set('employee_number', '1000003')
            ->assertSee('Adscribir a SC en C2')
            ->call('adscribeExisting')
            ->assertHasNoErrors();

        expect($this->carla->adscriptions()->where('department_id', $this->sc->id)->where('campus_id', $this->c2->id)->exists())->toBeTrue()
            ->and($this->carla->fresh()->is_active)->toBeFalse()
            ->and(lastToastOf($page))->title->toBe('Docente adscrito a SC en C2');
    });

    it('ve «Dar de alta usuarios» deshabilitado con su motivo', function () {
        $dom = domOf($this->actingAs($this->jefe)->get(route('faculty.teachers'))->assertOk());
        $button = collect($dom->querySelectorAll('[data-testid="catalog-toolbar"] button'))->first(fn ($button): bool => textOf($button) === 'Dar de alta usuarios');

        expect($button)->not->toBeNull()
            ->and($button->hasAttribute('disabled'))->toBeTrue()
            ->and($button->parentElement->getAttribute('data-tip'))->toBe('Solo el administrador da de alta usuarios.');
    });

    it('recibe 403 en cada método sobre un docente que no cubre, aunque lo llame directo', function (string $method, string $teacher) {
        $target = $this->{$teacher};

        Livewire::actingAs($this->jefe)->test('faculty::pages.teachers')->call($method, $target->id)->assertForbidden();

        expect($target->fresh()->is_active)->toBe($target->is_active)
            ->and(Activity::count())->toBe(0);
    })->with([
        'editar uno de SC en C1' => ['edit', 'beto'],
        'editar uno de II en C2' => ['edit', 'carla'],
        'desactivar uno de SC en C1' => ['toggleActive', 'beto'],
        'activar uno de II en C2' => ['toggleActive', 'carla'],
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
    it('ve a los mismos docentes que su jefe', function () {
        $jpd = User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->c2->id, 'department_id' => $this->sc->id])->create();

        expect(array_map('strval', array_keys(teacherRowsFor($jpd))))->toBe(['1000001', '1000004']);
    });
});

describe('subdirección de C2', function () {
    it('ve a todos los adscritos en C2 y a nadie solo de C1', function () {
        expect(array_map('strval', array_keys(teacherRowsFor($this->subdireccion))))->toBe(['1000001', '1000003', '1000004']);
    });

    it('elige el departamento; el campus es el suyo', function () {
        Livewire::actingAs($this->subdireccion)
            ->test('faculty::pages.teachers')
            ->call('create')
            ->assertSet('campus_id', $this->c2->id)
            ->assertSet('department_id', '')
            ->set('employee_number', '2000005')
            ->set('name', 'Irene Paz')
            ->set('department_id', $this->cb->id)
            ->call('save')
            ->assertHasNoErrors();

        expect(Teacher::where('employee_number', '2000005')->sole()->adscriptions()->sole())
            ->department_id->toBe($this->cb->id)
            ->campus_id->toBe($this->c2->id);
    });

    it('no da de alta en otro campus aunque lo mande el navegador', function () {
        Livewire::actingAs($this->subdireccion)
            ->test('faculty::pages.teachers')
            ->call('create')
            ->set('employee_number', '2000006')
            ->set('name', 'Jorge Sol')
            ->set('department_id', $this->cb->id)
            ->set('campus_id', $this->c1->id)
            ->call('save')
            ->assertHasErrors('department_id');

        expect(Teacher::where('employee_number', '2000006')->exists())->toBeFalse();
    });

    it('recibe 403 al desactivar a uno solo de C1', function () {
        Livewire::actingAs($this->subdireccion)->test('faculty::pages.teachers')->call('toggleActive', $this->beto->id)->assertForbidden();

        expect($this->beto->fresh()->is_active)->toBeTrue();
    });
});

describe('otros roles e invitados', function () {
    $roles = [
        'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)]],
        'docente' => [Role::Docente, []],
    ];

    it('reciben 403 en la página', function (Role $role, array $scope) {
        $user = User::factory()->withRole($role, ['campus_id' => $this->c2->id, ...$scope])->create();

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
            $page->call('create')->set('employee_number', '1000002')->set('department_id', $this->cb->id)->set('campus_id', $this->c2->id);
        }

        $this->actingAs($user);
        $page->call($method, ...(in_array($method, ['edit', 'toggleActive'], true) ? [$this->ana->id] : []))->assertForbidden();

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
        'dar de alta usuarios' => ['provisionUsers', false],
    ]);

    it('un invitado va al login', function () {
        $this->get(route('faculty.teachers'))->assertRedirect('/login');
    });
});

describe('menú', function () {
    it('la entrada Docentes, en Recursos humanos, la ven el admin, la subdirección, los jefes y los JPD, y no el coordinador ni el docente', function () {
        $item = collect(app(Navigation::class)->items())->firstWhere('label', 'Docentes');
        $jpd = User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->c2->id, 'department_id' => $this->sc->id])->create();
        $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])->create();
        $docente = User::factory()->withRole(Role::Docente, ['campus_id' => $this->c2->id])->create();

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

it('cada docente de la lista enlaza a su ficha, y el menú de Docentes se queda marcado dentro de ella', function () {
    $response = $this->actingAs($this->admin)->get(route('faculty.teachers'))->assertOk();
    $link = domOf($response)->querySelector('a[data-testid="record-1000001"]');

    expect($link)->not->toBeNull()
        ->and($link->getAttribute('href'))->toBe(route('faculty.teachers.show', $this->ana->id));

    $this->actingAs($this->admin)->get(route('faculty.teachers.show', $this->ana->id))->assertOk();
    expect(collect(app(Navigation::class)->visibleTo(app(ScopedContext::class)))->first(fn (NavigationItem $item): bool => $item->label === 'Docentes')->owns('faculty.teachers.show'))->toBeTrue();
});
