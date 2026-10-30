<?php

use App\Modules\Academic\Models\Career;
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
| CRUD de Planes de estudio (`/academic/plans`, academic::pages.plans). Solo el admin
| (reglas-de-negocio.md, "Aprobaciones y permisos", fila "Administrar catálogos de Academic"): ve la
| tabla con carrera, versión, semestres, créditos y materias en retícula; busca, filtra por carrera y
| estado; da de alta, edita, activa y desactiva. Cambiar la carrera de un plan con alumnos lo veta
| Students y el motivo sale en el campo.
*/

beforeEach(function () {
    $this->isc = Career::factory()->create(['code' => 'ISC', 'name' => 'Ingeniería en Sistemas']);
    $this->iia = Career::factory()->create(['code' => 'IIA', 'name' => 'Ingeniería en Inteligencia Artificial']);

    $this->plan224 = StudyPlan::factory()->for($this->isc)->create([
        'code' => 'ISIC-2010-224', 'name' => 'Sistemas 2010', 'version' => '2010', 'semesters_count' => 9, 'total_credits' => 260,
    ]);
    $this->plan2025 = StudyPlan::factory()->for($this->isc)->inactive()->create([
        'code' => 'ISIC-2025-001', 'name' => 'Sistemas 2025', 'version' => '2025', 'semesters_count' => null, 'total_credits' => null,
    ]);
    $this->iiaPlan = StudyPlan::factory()->for($this->iia)->create(['code' => 'IIA-2024-001', 'name' => 'IA 2024']);

    placeInPlan($this->plan224, Subject::factory()->create());
    placeInPlan($this->plan224, Subject::factory()->create(), 2);
    placeInPlan($this->plan224, Subject::factory()->create(), 3)->delete();

    $this->admin = User::factory()->admin()->create();
});

/**
 * @param  list<string>  $codes
 */
function listsPlans(array $codes): Closure
{
    return fn ($plans): bool => $plans->pluck('code')->all() === $codes;
}

function seesPlansMenu(User $user): bool
{
    test()->actingAs($user);

    return collect(app(Navigation::class)->visibleTo(app(ScopedContext::class)))
        ->contains(fn (NavigationItem $item): bool => $item->label === 'Planes de estudio');
}

describe('admin', function () {
    it('ve la tabla con clave, nombre, carrera, versión, semestres, créditos, materias y estado', function () {
        $response = $this->actingAs($this->admin)
            ->get(route('academic.plans'))
            ->assertOk()
            ->assertSeeInOrder(['Clave', 'Nombre', 'Carrera', 'Versión', 'Semestres', 'Créditos', 'Materias', 'Estado']);

        $rows = collect(domOf($response)->querySelectorAll('table tbody tr'))
            ->mapWithKeys(fn ($row) => [textOf($row->querySelector('td')) => textOf($row)]);

        expect($rows->keys()->all())->toBe(['IIA-2024-001', 'ISIC-2010-224', 'ISIC-2025-001'])
            ->and($rows['ISIC-2010-224'])->toContain('Sistemas 2010', 'ISC', '2010', '9', '260', '2 materias', 'Activo')
            ->and($rows['ISIC-2025-001'])->toContain('—', 'Sin materias', 'Inactivo');
    });

    it('enlaza a la retícula de cada plan, en la tabla y en los renglones del celular', function () {
        $page = domOf($this->actingAs($this->admin)->get(route('academic.plans')));
        $links = fn (string $scope): array => array_map(
            fn ($link): string => $link->getAttribute('href'),
            iterator_to_array($page->querySelectorAll($scope.' a[aria-label="Retícula de ISIC-2010-224"]')),
        );

        expect($links('table'))->toBe([route('academic.plans.show', $this->plan224)])
            ->and($links('[data-testid="plan-cards"]'))->toBe([route('academic.plans.show', $this->plan224)]);
    });

    it('en el celular apila cada plan en data-testid="plan-cards"', function () {
        $cards = domOf($this->actingAs($this->admin)->get(route('academic.plans')))
            ->querySelectorAll('[data-testid="plan-cards"] > li');

        expect(count($cards))->toBe(3)
            ->and(textOf($cards[1]))->toContain('ISIC-2010-224 Sistemas 2010 Activo')
            ->and(textOf($cards[1]))->toContain('ISC · 2010 · 9 semestres · 260 créditos · 2 materias');
    });

    it('busca por clave o nombre y filtra por carrera y estado', function () {
        Livewire::actingAs($this->admin)
            ->test('academic::pages.plans')
            ->assertViewHas('plans', listsPlans(['IIA-2024-001', 'ISIC-2010-224', 'ISIC-2025-001']))
            ->set('search', 'sistemas 2025')
            ->assertViewHas('plans', listsPlans(['ISIC-2025-001']))
            ->set('search', '')
            ->set('careerFilter', $this->isc->id)
            ->assertViewHas('plans', listsPlans(['ISIC-2010-224', 'ISIC-2025-001']))
            ->set('status', 'inactive')
            ->assertViewHas('plans', listsPlans(['ISIC-2025-001']))
            ->set('status', 'active')
            ->assertViewHas('plans', listsPlans(['ISIC-2010-224']))
            ->set('careerFilter', $this->iia->id)
            ->assertViewHas('plans', listsPlans(['IIA-2024-001']));
    });

    it('olvida un filtro de carrera que no está entre las opciones', function () {
        Livewire::actingAs($this->admin)
            ->test('academic::pages.plans')
            ->set('careerFilter', 'no-es-uuid')
            ->assertSet('careerFilter', '')
            ->assertViewHas('plans', listsPlans(['IIA-2024-001', 'ISIC-2010-224', 'ISIC-2025-001']));
    });

    it('da de alta un plan desde el modal y lo confirma con un toast', function () {
        $page = Livewire::actingAs($this->admin)
            ->test('academic::pages.plans')
            ->call('create')
            ->assertSet('showModal', true)
            ->set(['code' => 'iia-2026-001', 'name' => 'IA 2026', 'career_id' => $this->iia->id, 'version' => '2026', 'semesters_count' => '9', 'total_credits' => '270', 'valid_from' => '2026-08-01'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showModal', false);

        expect(StudyPlan::where('code', 'IIA-2026-001')->sole())
            ->career_id->toBe($this->iia->id)->semesters_count->toBe(9)->total_credits->toBe(270)->is_active->toBeTrue()
            ->and(lastToastOf($page))->type->toBe('success')->title->toBe('Plan creado')->description->toBe('IIA-2026-001 · IA 2026');
    });

    it('da de alta un plan con semestres y créditos vacíos', function () {
        Livewire::actingAs($this->admin)
            ->test('academic::pages.plans')
            ->call('create')
            ->set(['code' => 'X-1', 'name' => 'X', 'career_id' => $this->iia->id, 'version' => '1'])
            ->call('save')
            ->assertHasNoErrors();

        expect(StudyPlan::where('code', 'X-1')->sole())->semesters_count->toBeNull()->total_credits->toBeNull()->valid_from->toBeNull();
    });

    it('muestra en español los errores de validación y el de clave repetida', function () {
        Livewire::actingAs($this->admin)
            ->test('academic::pages.plans')
            ->call('create')
            ->call('save')
            ->assertHasErrors(['code' => 'required', 'name' => 'required', 'career_id' => 'required', 'version' => 'required'])
            ->assertSee('El campo clave es obligatorio.')
            ->set(['code' => 'X', 'name' => 'X', 'career_id' => $this->iia->id, 'version' => '1', 'semesters_count' => '0', 'total_credits' => '-1'])
            ->call('save')
            ->assertHasErrors(['semesters_count' => 'min', 'total_credits' => 'min'])
            ->set(['code' => 'isic-2010-224', 'semesters_count' => '', 'total_credits' => ''])
            ->call('save')
            ->assertHasErrors('code')
            ->assertSee('Ya existe un plan con la clave ISIC-2010-224');

        expect(StudyPlan::count())->toBe(3);
    });

    it('una carrera que no existe es un error en el campo', function () {
        Livewire::actingAs($this->admin)
            ->test('academic::pages.plans')
            ->call('create')
            ->set(['code' => 'X-1', 'name' => 'X', 'career_id' => fakeKey(77), 'version' => '1'])
            ->call('save')
            ->assertHasErrors('career_id')
            ->assertSee('La carrera no existe o está borrada.');
    });

    it('edita un plan', function () {
        $page = Livewire::actingAs($this->admin)
            ->test('academic::pages.plans')
            ->call('edit', $this->plan224->id)
            ->assertSet('showModal', true)
            ->assertSet('code', 'ISIC-2010-224')
            ->assertSet('career_id', $this->isc->id)
            ->assertSet('semesters_count', '9')
            ->assertSet('total_credits', '260')
            ->assertSet('valid_from', '2010-08-01')
            ->set('total_credits', '')
            ->set('name', 'Sistemas 2010 (rev.)')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showModal', false);

        expect($this->plan224->fresh())->total_credits->toBeNull()->name->toBe('Sistemas 2010 (rev.)')
            ->and(lastToastOf($page)['title'])->toBe('Plan actualizado');
    });

    it('cambia la carrera de un plan sin alumnos', function () {
        Livewire::actingAs($this->admin)
            ->test('academic::pages.plans')
            ->call('edit', $this->plan2025->id)
            ->set('career_id', $this->iia->id)
            ->call('save')
            ->assertHasNoErrors();

        expect($this->plan2025->fresh()->career_id)->toBe($this->iia->id);
    });

    it('si hay alumnos con el plan no cambia la carrera y lo dice en el campo', function () {
        Student::factory()->for($this->plan224)->create(['career_id' => $this->isc->id]);

        Livewire::actingAs($this->admin)
            ->test('academic::pages.plans')
            ->call('edit', $this->plan224->id)
            ->set('career_id', $this->iia->id)
            ->call('save')
            ->assertHasErrors('career_id')
            ->assertSee('Tiene 1 alumno inscrito en este plan.')
            ->assertSet('showModal', true);

        expect($this->plan224->fresh()->career_id)->toBe($this->isc->id);
    });

    it('desactiva y reactiva un plan', function () {
        $page = Livewire::actingAs($this->admin)->test('academic::pages.plans');

        $page->call('toggleActive', $this->plan224->id);
        expect($this->plan224->fresh()->is_active)->toBeFalse()
            ->and(lastToastOf($page)['title'])->toBe('Plan desactivado');

        $page->call('toggleActive', $this->plan224->id);
        expect($this->plan224->fresh()->is_active)->toBeTrue()
            ->and(lastToastOf($page)['title'])->toBe('Plan activado')
            ->and(Activity::where('subject_id', $this->plan224->id)->count())->toBe(2);
    });

    it('un id que no es uuid es un plan que no existe', function () {
        Livewire::actingAs($this->admin)
            ->test('academic::pages.plans')
            ->call('edit', 'no-es-uuid')
            ->assertNotFound();
    });

    it('carga 30 planes con sus carreras y conteos en pocas consultas', function () {
        $careers = Career::factory()->count(5)->create();

        foreach (range(1, 30) as $i) {
            $plan = StudyPlan::factory()->for($careers[$i % 5])->create();
            placeInPlan($plan, Subject::factory()->create());
        }

        $this->actingAs($this->admin);
        DB::enableQueryLog();
        $this->get(route('academic.plans'))->assertOk();
        $queries = count(DB::getQueryLog());

        // 10 medidas: constante con el número de planes (sesión, auth, carreras, página, conteo, relaciones).
        expect($queries)->toBe(10);
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

        $this->actingAs($user)->get(route('academic.plans'))->assertForbidden();
    })->with($roles);

    it('reciben 403 en cada método del componente aunque lo llamen directo con una página que abrió el admin', function (string $method, bool $editFirst) {
        $user = User::factory()->withRole(Role::Coordinador, ['campus_id' => Campus::factory()->create()->id, 'career_id' => careerKey(20)])->create();
        $page = Livewire::actingAs($this->admin)->test('academic::pages.plans');

        if ($editFirst) {
            $page->call('edit', $this->plan224->id);
        } else {
            $page->call('create')->set(['code' => 'NEW-1', 'name' => 'Nuevo', 'career_id' => $this->isc->id, 'version' => '1']);
        }

        $this->actingAs($user);
        $page->call($method, ...(in_array($method, ['edit', 'toggleActive'], true) ? [$this->plan224->id] : []))->assertForbidden();

        expect(StudyPlan::count())->toBe(3)
            ->and($this->plan224->fresh()->is_active)->toBeTrue()
            ->and(Activity::where('subject_type', $this->plan224->getMorphClass())->count())->toBe(0);
    })->with([
        'nuevo' => ['create', false],
        'editar' => ['edit', false],
        'guardar un alta' => ['save', false],
        'guardar una edición' => ['save', true],
        'activar o desactivar' => ['toggleActive', false],
    ]);

    it('un invitado va al login', function () {
        $this->get(route('academic.plans'))->assertRedirect('/login');
    });
});

describe('menú', function () {
    it('la entrada Planes de estudio apunta a la página, va después de Materias y la ve solo el admin', function () {
        $items = collect(app(Navigation::class)->items());
        $item = $items->firstWhere('label', 'Planes de estudio');
        $position = fn (string $label): int|false => $items->search(fn (NavigationItem $candidate): bool => $candidate->label === $label);
        $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => Campus::factory()->create()->id, 'career_id' => careerKey(20)])->create();

        expect($item)->route->toBe('academic.plans')
            ->and($position('Planes de estudio'))->toBeGreaterThan($position('Materias'))
            ->and(route('academic.plans', absolute: false))->toBe('/academic/plans')
            ->and(seesPlansMenu($this->admin))->toBeTrue()
            ->and(seesPlansMenu($coordinador))->toBeFalse();
    });
});
