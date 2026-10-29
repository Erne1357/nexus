<?php

use App\Modules\Academic\Enums\SubjectKind;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Students\Models\Student;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| CRUD de Materias (`/academic/subjects`, academic::pages.subjects). Solo el admin
| (reglas-de-negocio.md, "Aprobaciones y permisos", fila "Administrar catálogos de Academic"): ve la
| tabla con horas, créditos, tipo, laboratorio y en cuántos planes está; busca, filtra por
| departamento, tipo y estado; da de alta, edita, activa y desactiva. Las materias no se borran.
| Desactivar una que todavía necesitan alumnos lo veta Students y el motivo sale en un toast.
*/

beforeEach(function () {
    $this->sis = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    $this->ele = Department::factory()->create(['code' => 'ELE', 'name' => 'Eléctrica-Electrónica']);

    $this->calculo = Subject::factory()->create([
        'code' => 'ACF-0901', 'name' => 'Cálculo Diferencial', 'department_id' => $this->sis->id,
        'theory_hours' => 3, 'practice_hours' => 2, 'credits' => 5, 'kind' => SubjectKind::Basica,
    ]);
    $this->topicos = Subject::factory()->create([
        'code' => 'SCD-1027', 'name' => 'Tópicos Avanzados de Programación', 'department_id' => $this->sis->id,
        'theory_hours' => 2, 'practice_hours' => 3, 'credits' => 5, 'kind' => SubjectKind::Especialidad, 'needs_lab' => true,
    ]);
    $this->circuitos = Subject::factory()->inactive()->create([
        'code' => 'AEF-1009', 'name' => 'Circuitos Eléctricos', 'department_id' => $this->ele->id,
        'theory_hours' => 3, 'practice_hours' => 3, 'credits' => 6, 'kind' => null,
    ]);

    $this->isc = StudyPlan::factory()->create(['code' => 'ISIC-2010-224']);
    placeInPlan($this->isc, $this->calculo);
    placeInPlan(StudyPlan::factory()->create(), $this->calculo);
    placeInPlan(StudyPlan::factory()->create(), $this->calculo, 1)->delete();
    placeInPlan($this->isc, $this->topicos, 7);

    $this->admin = User::factory()->admin()->create();
});

/**
 * @param  list<string>  $codes
 * @return Closure(mixed): bool
 */
function listsSubjects(array $codes): Closure
{
    return fn ($subjects): bool => $subjects->pluck('code')->all() === $codes;
}

function seesSubjectsMenu(User $user): bool
{
    test()->actingAs($user);

    return collect(app(Navigation::class)->visibleTo(app(ScopedContext::class)))
        ->contains(fn (NavigationItem $item): bool => $item->label === 'Materias');
}

describe('admin', function () {
    it('ve la tabla con clave, nombre, departamento, HT-HP-créditos, tipo, laboratorio, planes y estado', function () {
        $response = $this->actingAs($this->admin)
            ->get(route('academic.subjects'))
            ->assertOk()
            ->assertSeeInOrder(['Clave', 'Nombre', 'Departamento', 'HT-HP-Créditos', 'Tipo', 'Laboratorio', 'Planes', 'Estado']);

        $rows = collect(domOf($response)->querySelectorAll('table tbody tr'))
            ->mapWithKeys(fn ($row) => [textOf($row->querySelector('td')) => textOf($row)]);

        expect($rows->keys()->all())->toBe(['ACF-0901', 'AEF-1009', 'SCD-1027'])
            ->and($rows['ACF-0901'])->toContain('Cálculo Diferencial', 'SIS', '3-2-5', 'Básica', 'En 2 planes', 'Activa')
            ->and($rows['SCD-1027'])->toContain('2-3-5', 'Especialidad', 'Sí', 'En 1 plan')
            ->and($rows['AEF-1009'])->toContain('ELE', '3-3-6', 'Sin tipo', 'En ningún plan', 'Inactiva');
    });

    it('en el celular apila cada materia en data-testid="subject-cards"', function () {
        $cards = domOf($this->actingAs($this->admin)->get(route('academic.subjects')))
            ->querySelectorAll('[data-testid="subject-cards"] > li');

        expect(count($cards))->toBe(3)
            ->and(textOf($cards[0]))->toContain('ACF-0901 Cálculo Diferencial Activa')
            ->and(textOf($cards[0]))->toContain('SIS · 3-2-5 · Básica · En 2 planes');
    });

    it('busca por clave o nombre y filtra por departamento, tipo y estado', function () {
        Livewire::actingAs($this->admin)
            ->test('academic::pages.subjects')
            ->assertViewHas('subjects', listsSubjects(['ACF-0901', 'AEF-1009', 'SCD-1027']))
            ->set('search', 'tópicos')
            ->assertViewHas('subjects', listsSubjects(['SCD-1027']))
            ->set('search', '')
            ->set('departmentFilter', $this->ele->id)
            ->assertViewHas('subjects', listsSubjects(['AEF-1009']))
            ->set('departmentFilter', '')
            ->set('kindFilter', 'especialidad')
            ->assertViewHas('subjects', listsSubjects(['SCD-1027']))
            ->set('kindFilter', 'none')
            ->assertViewHas('subjects', listsSubjects(['AEF-1009']))
            ->set('kindFilter', '')
            ->set('status', 'inactive')
            ->assertViewHas('subjects', listsSubjects(['AEF-1009']))
            ->set('status', 'active')
            ->set('departmentFilter', $this->sis->id)
            ->assertViewHas('subjects', listsSubjects(['ACF-0901', 'SCD-1027']));
    });

    it('olvida un filtro de departamento o tipo que no está entre las opciones', function () {
        Livewire::actingAs($this->admin)
            ->test('academic::pages.subjects')
            ->set('departmentFilter', 'no-es-uuid')
            ->set('kindFilter', 'optativa')
            ->assertSet('departmentFilter', '')
            ->assertSet('kindFilter', '')
            ->assertViewHas('subjects', listsSubjects(['ACF-0901', 'AEF-1009', 'SCD-1027']));
    });

    it('da de alta una materia desde el modal y lo confirma con un toast', function () {
        $page = Livewire::actingAs($this->admin)
            ->test('academic::pages.subjects')
            ->call('create')
            ->assertSet('showModal', true)
            ->set('code', 'scd-1003')
            ->set('name', 'Arquitectura de Computadoras')
            ->set('department_id', $this->sis->id)
            ->set('theory_hours', '2')
            ->set('practice_hours', '3')
            ->set('credits', '5')
            ->set('kind', 'especialidad')
            ->set('needs_lab', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showModal', false);

        expect(Subject::where('code', 'SCD-1003')->sole())
            ->name->toBe('Arquitectura de Computadoras')
            ->department_id->toBe($this->sis->id)
            ->theory_hours->toBe(2)->practice_hours->toBe(3)->credits->toBe(5)
            ->kind->toBe(SubjectKind::Especialidad)
            ->needs_lab->toBeTrue()
            ->and(lastToastOf($page))->type->toBe('success')->title->toBe('Materia creada')->description->toBe('SCD-1003 · Arquitectura de Computadoras');
    });

    it('da de alta una materia sin tipo', function () {
        Livewire::actingAs($this->admin)
            ->test('academic::pages.subjects')
            ->call('create')
            ->set(['code' => 'ACA-0907', 'name' => 'Taller de Ética', 'department_id' => $this->ele->id, 'theory_hours' => '0', 'practice_hours' => '4', 'credits' => '4'])
            ->call('save')
            ->assertHasNoErrors();

        expect(Subject::where('code', 'ACA-0907')->sole()->kind)->toBeNull();
    });

    it('muestra en español los errores de validación y el de clave repetida', function () {
        Livewire::actingAs($this->admin)
            ->test('academic::pages.subjects')
            ->call('create')
            ->call('save')
            ->assertHasErrors(['code' => 'required', 'name' => 'required', 'department_id' => 'required', 'theory_hours' => 'required', 'credits' => 'required'])
            ->assertSee('El campo clave es obligatorio.')
            ->assertSee('El campo departamento es obligatorio.')
            ->set(['code' => 'X', 'name' => 'X', 'department_id' => $this->sis->id, 'theory_hours' => '-1', 'practice_hours' => 'dos', 'credits' => '5', 'kind' => 'optativa'])
            ->call('save')
            ->assertHasErrors(['theory_hours' => 'min', 'practice_hours' => 'integer', 'kind'])
            ->set(['code' => 'acf-0901', 'theory_hours' => '1', 'practice_hours' => '1', 'kind' => ''])
            ->call('save')
            ->assertHasErrors('code')
            ->assertSee('Ya existe una materia con la clave ACF-0901');

        expect(Subject::count())->toBe(3);
    });

    it('un departamento que no existe es un error en el campo', function () {
        Livewire::actingAs($this->admin)
            ->test('academic::pages.subjects')
            ->call('create')
            ->set(['code' => 'ACA-0907', 'name' => 'Taller de Ética', 'department_id' => fakeKey(77), 'theory_hours' => '0', 'practice_hours' => '4', 'credits' => '4'])
            ->call('save')
            ->assertHasErrors('department_id')
            ->assertSee('El departamento no existe o está borrado.');
    });

    it('edita una materia', function () {
        $page = Livewire::actingAs($this->admin)
            ->test('academic::pages.subjects')
            ->call('edit', $this->topicos->id)
            ->assertSet('showModal', true)
            ->assertSet('code', 'SCD-1027')
            ->assertSet('name', 'Tópicos Avanzados de Programación')
            ->assertSet('department_id', $this->sis->id)
            ->assertSet('theory_hours', '2')
            ->assertSet('practice_hours', '3')
            ->assertSet('credits', '5')
            ->assertSet('kind', 'especialidad')
            ->assertSet('needs_lab', true)
            ->set('credits', '6')
            ->set('kind', '')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showModal', false);

        expect($this->topicos->fresh())->credits->toBe(6)->kind->toBeNull()
            ->and(lastToastOf($page)['title'])->toBe('Materia actualizada');
    });

    it('desactiva y reactiva una materia que nadie necesita', function () {
        $page = Livewire::actingAs($this->admin)->test('academic::pages.subjects');

        $page->call('toggleActive', $this->topicos->id);
        expect($this->topicos->fresh()->is_active)->toBeFalse()
            ->and(lastToastOf($page)['title'])->toBe('Materia desactivada');

        $page->call('toggleActive', $this->topicos->id);
        expect($this->topicos->fresh()->is_active)->toBeTrue()
            ->and(lastToastOf($page)['title'])->toBe('Materia activada');
    });

    it('si alumnos la necesitan no la desactiva y muestra el motivo en un toast de error', function () {
        Student::factory()->for($this->isc)->create(['career_id' => $this->isc->career_id]);

        $page = Livewire::actingAs($this->admin)
            ->test('academic::pages.subjects')
            ->call('toggleActive', $this->calculo->id);

        expect($this->calculo->fresh()->is_active)->toBeTrue()
            ->and(lastToastOf($page))
            ->type->toBe('error')
            ->title->toBe('No se pudo desactivar ACF-0901')
            ->description->toBe('La necesita 1 alumno (activo o en baja temporal) del plan ISIC-2010-224.');
    });

    it('un id que no es uuid es una materia que no existe', function () {
        Livewire::actingAs($this->admin)
            ->test('academic::pages.subjects')
            ->call('edit', 'no-es-uuid')
            ->assertNotFound();
    });

    it('carga 30 materias de 5 planes con sus departamentos y conteos en pocas consultas', function () {
        $plans = StudyPlan::factory()->count(5)->create();
        $departments = Department::factory()->count(5)->create();

        foreach (range(1, 27) as $i) {
            $subject = Subject::factory()->create(['department_id' => $departments[$i % 5]->id]);
            placeInPlan($plans[$i % 5], $subject);
            placeInPlan($plans[($i + 1) % 5], $subject);
        }

        $this->actingAs($this->admin);
        DB::enableQueryLog();
        $this->get(route('academic.subjects'))->assertOk()->assertSee('En 2 planes');
        $queries = count(DB::getQueryLog());

        expect($queries)->toBeLessThanOrEqual(11);
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

        $this->actingAs($user)->get(route('academic.subjects'))->assertForbidden();
    })->with($roles);

    it('reciben 403 en cada método del componente aunque lo llamen directo con una página que abrió el admin', function (string $method, bool $editFirst) {
        $user = User::factory()->withRole(Role::Coordinador, ['campus_id' => Campus::factory()->create()->id, 'career_id' => careerKey(20)])->create();
        $page = Livewire::actingAs($this->admin)->test('academic::pages.subjects');

        if ($editFirst) {
            $page->call('edit', $this->topicos->id);
        } else {
            $page->call('create')->set(['code' => 'NEW-0001', 'name' => 'Nueva', 'department_id' => $this->sis->id, 'theory_hours' => '1', 'practice_hours' => '1', 'credits' => '2']);
        }

        $this->actingAs($user);
        $page->call($method, ...(in_array($method, ['edit', 'toggleActive'], true) ? [$this->topicos->id] : []))->assertForbidden();

        expect(Subject::count())->toBe(3)
            ->and($this->topicos->fresh()->is_active)->toBeTrue()
            ->and(Activity::where('subject_type', $this->topicos->getMorphClass())->count())->toBe(0);
    })->with([
        'nueva' => ['create', false],
        'editar' => ['edit', false],
        'guardar un alta' => ['save', false],
        'guardar una edición' => ['save', true],
        'activar o desactivar' => ['toggleActive', false],
    ]);

    it('un invitado va al login', function () {
        $this->get(route('academic.subjects'))->assertRedirect('/login');
    });
});

describe('menú', function () {
    it('la entrada Materias apunta a la página, va después de Departamentos y la ve solo el admin', function () {
        $items = collect(app(Navigation::class)->items());
        $item = $items->firstWhere('label', 'Materias');
        $position = fn (string $label): int|false => $items->search(fn (NavigationItem $candidate): bool => $candidate->label === $label);
        $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => Campus::factory()->create()->id, 'career_id' => careerKey(20)])->create();

        expect($item)->route->toBe('academic.subjects')->icon->toBe('o-book-open')
            ->and($position('Materias'))->toBeGreaterThan($position('Departamentos'))
            ->and(route('academic.subjects', absolute: false))->toBe('/academic/subjects')
            ->and(seesSubjectsMenu($this->admin))->toBeTrue()
            ->and(seesSubjectsMenu($coordinador))->toBeFalse();
    });
});
