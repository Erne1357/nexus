<?php

use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| Retícula de un plan (`/academic/plans/{plan}`, academic::pages.plan; tarea 1.8, variante A aprobada el 29 de
| octubre de 2026): el encabezado del plan, sus especialidades y sus materias en columnas por semestre, con su
| seriación y sus cadenas. Tocar una materia la muestra en el panel lateral (su lugar, lo que pide y quién la
| pide); en el mismo panel se gestionan las especialidades: crear, renombrar, desactivar y quitar solo sin
| materias (S1). Solo el admin (reglas-de-negocio.md, "Administrar catálogos de Academic").
*/

/**
 * El plan de las pruebas: cuatro semestres, dos especialidades, una cadena de tres materias, un correquisito,
 * una optativa y una materia inactiva.
 */
function planFixture(object $test): void
{
    $department = Department::factory()->create(['name' => 'Ciencias Básicas']);
    $test->career = Career::factory()->create(['code' => 'ISIC', 'name' => 'Ingeniería en Sistemas Computacionales']);
    $test->plan = StudyPlan::factory()->for($test->career)->create([
        'code' => 'ISIC-2010-224', 'name' => 'Sistemas 2010', 'version' => '2010', 'semesters_count' => 4, 'total_credits' => 260,
    ]);
    $test->software = Specialty::factory()->for($test->plan)->create(['code' => 'ISIC-ISW-2020', 'name' => 'Ingeniería de Software']);
    $test->networks = Specialty::factory()->for($test->plan)->create(['code' => 'ISIC-RCS-2020', 'name' => 'Redes y Ciberseguridad']);

    $subject = fn (string $code, string $name, array $attributes = []): Subject => Subject::factory()->for($department)->create([
        'code' => $code, 'name' => $name, 'theory_hours' => 3, 'practice_hours' => 2, 'credits' => 5, ...$attributes,
    ]);
    $test->calculus = $subject('ACF-0901', 'Cálculo Diferencial');
    $test->integral = $subject('ACF-0902', 'Cálculo Integral');
    $test->vectorial = $subject('ACF-0904', 'Cálculo Vectorial');
    $test->numerical = $subject('SCC-1017', 'Métodos Numéricos', ['theory_hours' => 2, 'practice_hours' => 2, 'credits' => 4]);
    $test->culture = $subject('SCC-1005', 'Cultura Empresarial', ['is_active' => false]);
    $test->architecture = $subject('ISD-2001', 'Arquitectura de Software');

    $test->calculusRow = placeInPlan($test->plan, $test->calculus);
    $test->integralRow = placeInPlan($test->plan, $test->integral, 2);
    placeInPlan($test->plan, $test->vectorial, 3);
    placeInPlan($test->plan, $test->numerical, 2)->update(['is_required' => false]);
    placeInPlan($test->plan, $test->culture, 3);
    placeInPlan($test->plan, $test->architecture, 4, $test->software);
    seriate($test->plan, $test->integral, $test->calculus);
    seriate($test->plan, $test->vectorial, $test->integral);
    seriate($test->plan, $test->integral, $test->numerical, PrerequisiteKind::Correquisito);

    $test->admin = User::factory()->admin()->create();
}

function planPage(User $user, StudyPlan $plan)
{
    return Livewire::actingAs($user)->test('academic::pages.plan', ['plan' => $plan->id]);
}

it('muestra el plan sembrado: su encabezado, sus semestres con sus materias y sus especialidades', function () {
    $this->seed(DemoSeeder::class);
    $plan = StudyPlan::where('code', 'ISIC-2010-224')->sole();
    $page = domOf($this->actingAs(User::factory()->admin()->create())->get(route('academic.plans.show', $plan))->assertOk());

    expect(textOf($page->querySelector('h1')))->toContain('ISIC-2010-224', 'Activo')
        ->and(textOf($page->querySelector('[data-testid="plan-facts"]')))->toContain('Carrera ISIC', 'Versión 2010', 'Semestres 9', 'Créditos 260')
        ->and($page->querySelectorAll('[data-testid="plan-grid"] > section'))->toHaveCount(9)
        ->and(textOf($page->querySelector('[data-subject="ACF-0902"]')))->toContain('ACF-0902', 'Cálculo Integral', 'Pide ACF-0901', '3-2-5')
        ->and(textOf($page->querySelector('[data-testid="specialty-chips"]')))->toContain('Ingeniería de Software 5', 'Redes y Ciberseguridad 5')
        ->and(textOf($page->querySelector('[data-testid="specialty-chips"]')))->not->toContain('Redes y Telecomunicaciones');
});

describe('admin', function () {
    beforeEach(fn () => planFixture($this));

    it('muestra cada semestre con sus materias, el tronco común antes que cada especialidad', function () {
        $page = domOf(planPage($this->admin, $this->plan));
        $semester = fn (int $number): string => textOf($page->querySelector('[data-semester="'.$number.'"]'));

        expect($page->querySelectorAll('[data-testid="plan-grid"] > section'))->toHaveCount(4)
            ->and($semester(1))->toContain('Semestre 1', '1 materia', '5 cr', 'ACF-0901 Cálculo Diferencial')
            ->and($semester(2))->toContain('2 materias', '9 cr')
            ->and($semester(4))->toContain('1 materia', '0 cr + esp.', 'Especialidad Ingeniería de Software', 'ISD-2001')
            ->and(textOf($page->querySelector('h1')))->toContain('Sistemas 2010', 'ISIC-2010-224', 'Activo')
            ->and(textOf($page->querySelector('[data-testid="plan-facts"]')))->toContain('Carrera ISIC · Ingeniería en Sistemas Computacionales', 'Versión 2010', 'Semestres 4', 'Créditos 260', 'Materias 6');
    });

    it('cada materia lleva su clave, nombre, lo que pide, si es optativa o inactiva, y sus horas y créditos', function () {
        $page = domOf(planPage($this->admin, $this->plan));
        $tile = fn (string $code) => $page->querySelector('[data-subject="'.$code.'"]');

        expect(textOf($tile('ACF-0902')))->toBe('ACF-0902 Cálculo Integral Pide ACF-0901 Correq. SCC-1017 3-2-5 Obligatoria')
            ->and(textOf($tile('SCC-1017')))->toBe('SCC-1017 Métodos Numéricos Optativa 2-2-4')
            ->and($tile('SCC-1017')->getAttribute('class'))->toContain('border-dashed')
            ->and(textOf($tile('SCC-1005')))->toBe('SCC-1005 Cultura Empresarial Inactiva 3-2-5 Obligatoria');
    });

    it('cada cadena de seriación lleva su color en la franja de sus materias y en la leyenda, con su nombre', function () {
        $page = domOf(planPage($this->admin, $this->plan));
        $tile = fn (string $code) => $page->querySelector('[data-subject="'.$code.'"]');

        expect(textOf($page->querySelector('[data-testid="chain-legend"] [data-chain="0"]')))->toBe('Cálculo Diferencial 3')
            ->and($tile('ACF-0904')->getAttribute('data-chain'))->toBe('0')
            ->and($tile('ACF-0901')->getAttribute('style'))->toContain('--chain: var(--color-chain-1)')
            ->and($tile('SCC-1017')->hasAttribute('data-chain'))->toBeFalse();
    });

    it('tocar una materia abre el panel con su lugar, lo que pide y quién la pide, y resalta su cadena', function () {
        $component = planPage($this->admin, $this->plan)
            ->call('select', $this->integralRow->id)
            ->assertSet('showDrawer', true)
            ->assertSet('panel', 'subject')
            ->assertSet('selectedId', $this->integralRow->id);
        $page = domOf($component);

        expect(textOf($page->querySelector('[data-testid="subject-panel"] header')))->toContain('ACF-0902', 'Cálculo Integral', '3-2-5 · Ciencias Básicas')
            ->and(textOf($page->querySelector('[data-testid="placement-section"]')))->toContain('Semestre 2', 'Especialidad Ninguna (tronco común)', 'Obligatoria')
            ->and(textOf($page->querySelector('[data-testid="requires-section"]')))->toContain('Pide 2', 'ACF-0901 Cálculo Diferencial Semestre 1 Seriación', 'SCC-1017 Métodos Numéricos Semestre 2 Correquisito')
            ->and(textOf($page->querySelector('[data-testid="required-by-section"]')))->toContain('La piden 1', 'ACF-0904 Cálculo Vectorial Semestre 3 Seriación')
            ->and($page->querySelector('[data-testid="plan-grid"]')->hasAttribute('data-focus'))->toBeTrue()
            ->and($page->querySelector('[data-subject="ACF-0902"]')->hasAttribute('data-me'))->toBeTrue()
            ->and($page->querySelector('[data-subject="ACF-0901"]')->hasAttribute('data-on-chain'))->toBeTrue()
            ->and($page->querySelector('[data-subject="ACF-0904"]')->hasAttribute('data-on-chain'))->toBeTrue()
            ->and($page->querySelector('[data-subject="SCC-1017"]')->hasAttribute('data-on-chain'))->toBeFalse()
            ->and(textOf($page->querySelector('[data-testid="chain-info"]')))
            ->toBe('ACF-0902 Cálculo Integral: pide ACF-0901 Cálculo Diferencial; correquisito SCC-1017 Métodos Numéricos · abre ACF-0904 Cálculo Vectorial');
    });

    it('el panel de una materia de especialidad dice cuál, y el de una sin seriación lo dice', function () {
        $row = $this->plan->placements()->where('subject_id', $this->architecture->id)->sole();
        $page = domOf(planPage($this->admin, $this->plan)->call('select', $row->id));

        expect(textOf($page->querySelector('[data-testid="placement-section"]')))->toContain('Semestre 4', 'Especialidad Ingeniería de Software')
            ->and(textOf($page->querySelector('[data-testid="requires-section"]')))->toContain('No pide ninguna materia.')
            ->and(textOf($page->querySelector('[data-testid="required-by-section"]')))->toContain('Ninguna materia la pide.');
    });

    it('el panel es un diálogo modal nombrado por su título', function () {
        $page = domOf(planPage($this->admin, $this->plan)->call('select', $this->integralRow->id));
        $dialog = $page->querySelector('[data-testid="plan-drawer"]');

        expect($dialog->getAttribute('role'))->toBe('dialog')
            ->and($dialog->getAttribute('aria-modal'))->toBe('true')
            ->and($dialog->getAttribute('aria-labelledby'))->toBe('plan-drawer-title')
            ->and(textOf($dialog->querySelector('#plan-drawer-title')))->toBe('Cálculo Integral')
            ->and($page->querySelectorAll('#plan-drawer-title'))->toHaveCount(1);
    });

    it('cerrar el panel quita la selección y el resaltado', function () {
        $page = planPage($this->admin, $this->plan)
            ->call('select', $this->integralRow->id)
            ->set('showDrawer', false)
            ->assertSet('selectedId', null);

        expect(domOf($page)->querySelector('[data-testid="plan-grid"]')->hasAttribute('data-focus'))->toBeFalse();
    });

    it('una petición que abre el panel sin una materia elegida lo deja cerrado', function () {
        planPage($this->admin, $this->plan)
            ->set('showDrawer', true)
            ->assertSet('showDrawer', false)
            ->call('select', $this->integralRow->id)
            ->set('showDrawer', false)
            ->set('showDrawer', true)
            ->assertSet('showDrawer', false)
            ->assertSet('selectedId', null)
            ->call('openSpecialties')
            ->set('showDrawer', false)
            ->set('showDrawer', true)
            ->assertSet('showDrawer', true)
            ->assertSet('panel', 'specialties');
    });

    it('el resaltado se salta un requisito que no está en la retícula en lugar de fallar', function () {
        $requisite = fn (string $id, string $code): array => ['prerequisite_id' => fakeKey(1), 'subject_id' => $id, 'code' => $code, 'name' => "Materia {$code}", 'semester' => 1, 'kind' => 'seriacion'];
        $rows = [
            ['subject_id' => 'a', 'code' => 'A-1', 'name' => 'Materia A-1', 'chain' => null, 'requires' => [$requisite('b', 'B-1'), $requisite('fuera', 'X-1')], 'required_by' => []],
            ['subject_id' => 'b', 'code' => 'B-1', 'name' => 'Materia B-1', 'chain' => null, 'requires' => [$requisite('otra-fuera', 'Y-1')], 'required_by' => [$requisite('a', 'A-1'), $requisite('fuera', 'X-1')]],
        ];
        $component = planPage($this->admin, $this->plan)->instance();

        // highlight() es privado: se llama con una retícula armada a mano, que PlanGridEditorQuery nunca da.
        $highlight = (fn (): array => $this->highlight(['chains' => []], $rows, $rows[0]))->call($component);

        expect($highlight['onChain'])->toBe(['a' => true, 'b' => true])
            ->and($highlight['focused'])->toBeTrue();
    });

    it('una fila de otro plan, quitada o con un id que no es uuid no existe', function (Closure $id) {
        planPage($this->admin, $this->plan)->call('select', $id($this))->assertNotFound();
    })->with([
        'de otro plan' => [fn ($test) => placeInPlan(StudyPlan::factory()->create(), Subject::factory()->create())->id],
        'quitada' => [fn ($test) => tap(placeInPlan($test->plan, Subject::factory()->create()))->delete()->id],
        'no uuid' => [fn () => 'no-es-uuid'],
    ]);

    it('tocar una cadena de la leyenda la resalta completa; tocarla otra vez o Esc la quita', function () {
        $component = planPage($this->admin, $this->plan)->call('focusChain', 0)->assertSet('chainFocus', 0);
        $page = domOf($component);

        expect($page->querySelector('[data-subject="ACF-0901"]')->hasAttribute('data-on-chain'))->toBeTrue()
            ->and($page->querySelector('[data-subject="ACF-0904"]')->hasAttribute('data-on-chain'))->toBeTrue()
            ->and($page->querySelector('[data-subject="SCC-1017"]')->hasAttribute('data-on-chain'))->toBeFalse()
            ->and($page->querySelector('[data-testid="chain-legend"] [data-chain="0"]')->getAttribute('aria-pressed'))->toBe('true')
            ->and(textOf($page->querySelector('[data-testid="chain-info"]')))->toBe('Cadena de Cálculo Diferencial: 3 materias.');

        $component->call('focusChain', 0)->assertSet('chainFocus', null)
            ->call('focusChain', 0)->call('clearFocus')->assertSet('chainFocus', null)
            ->call('focusChain', 7)->assertSet('chainFocus', null);
    });

    it('lleva la ruta del listado de planes y la ubica en el menú', function () {
        $page = domOf($this->actingAs($this->admin)->get(route('academic.plans.show', $this->plan))->assertOk());

        expect(route('academic.plans.show', $this->plan, absolute: false))->toBe('/academic/plans/'.$this->plan->id)
            ->and($page->querySelector('[data-testid="back-to-plans"]')->getAttribute('href'))->toBe(route('academic.plans'))
            ->and(textOf($page->querySelector('[data-testid="back-to-plans"]')))->toBe('Planes de estudio')
            ->and(textOf($page->querySelector('#nexus-menu [aria-current="true"]')))->toBe('Planes de estudio');
    });

    it('un plan que no existe, borrado o con un id que no es uuid da 404', function () {
        $deleted = StudyPlan::factory()->create();
        $deleted->delete();

        $this->actingAs($this->admin)->get('/academic/plans/no-es-uuid')->assertNotFound();
        $this->actingAs($this->admin)->get('/academic/plans/'.fakeKey(99))->assertNotFound();
        $this->actingAs($this->admin)->get(route('academic.plans.show', $deleted))->assertNotFound();
    });

    it('carga una retícula de 30 materias con su seriación en un número fijo de consultas', function () {
        $previous = null;

        foreach (range(1, 30) as $i) {
            $subject = Subject::factory()->create();
            placeInPlan($this->plan, $subject, intdiv($i - 1, 4) + 1, $i > 26 ? $this->software : null);

            if ($previous !== null) {
                seriate($this->plan, $subject, $previous);
            }

            $previous = $subject;
        }

        $this->actingAs($this->admin);
        DB::enableQueryLog();
        $this->get(route('academic.plans.show', $this->plan))->assertOk();
        $queries = count(DB::getQueryLog());

        // 12 medidas, constantes con el número de materias: el plan (en mount y al pintar) con su carrera,
        // permisos y alcances, la retícula (3: materias, seriación y especialidades) y la credencial.
        expect($queries)->toBe(12);
    });
});

describe('especialidades', function () {
    beforeEach(fn () => planFixture($this));

    it('abre las especialidades en el panel con su clave, nombre, estado y materias', function () {
        $page = domOf(planPage($this->admin, $this->plan)->call('openSpecialties')->assertSet('showDrawer', true)->assertSet('panel', 'specialties'));

        expect(textOf($page->querySelector('[data-specialty="ISIC-ISW-2020"]')))->toContain('ISIC-ISW-2020', 'Activa', 'Ingeniería de Software', '1 materia')
            ->and(textOf($page->querySelector('[data-specialty="ISIC-RCS-2020"]')))->toContain('Sin materias todavía');
    });

    it('da de alta una especialidad y lo confirma con un toast', function () {
        $component = planPage($this->admin, $this->plan)
            ->call('openSpecialties')
            ->set(['code' => 'isic-cda-2022', 'name' => 'Ciencia de Datos'])
            ->call('createSpecialty')
            ->assertHasNoErrors()
            ->assertSet('code', '')
            ->assertSet('name', '')
            ->assertSee('ISIC-CDA-2022');

        expect($this->plan->specialties()->where('code', 'ISIC-CDA-2022')->sole())->name->toBe('Ciencia de Datos')->is_active->toBeTrue()
            ->and(lastToastOf($component))->type->toBe('success')->title->toBe('Especialidad creada')->description->toBe('ISIC-CDA-2022 · Ciencia de Datos');
    });

    it('muestra en español los errores de la especialidad nueva y el de clave repetida', function () {
        planPage($this->admin, $this->plan)
            ->call('openSpecialties')
            ->call('createSpecialty')
            ->assertHasErrors(['code' => 'required', 'name' => 'required'])
            ->assertSee('El campo clave es obligatorio.')
            ->set(['code' => 'isic-isw-2020', 'name' => 'Otra'])
            ->call('createSpecialty')
            ->assertHasErrors('code')
            ->assertSee('Ya existe una especialidad con la clave ISIC-ISW-2020 en este plan, aunque esté quitada.');

        expect($this->plan->specialties()->count())->toBe(2);
    });

    it('renombra una especialidad en su lugar', function () {
        $component = planPage($this->admin, $this->plan)
            ->call('openSpecialties')
            ->call('startRename', $this->software->id)
            ->assertSet('renamingId', $this->software->id)
            ->assertSet('renamedName', 'Ingeniería de Software')
            ->set('renamedName', '')
            ->call('saveRename')
            ->assertHasErrors(['renamedName' => 'required'])
            ->set('renamedName', 'Software Empresarial')
            ->call('saveRename')
            ->assertHasNoErrors()
            ->assertSet('renamingId', null);

        expect($this->software->fresh()->name)->toBe('Software Empresarial')
            ->and(lastToastOf($component))->title->toBe('Especialidad renombrada')->description->toBe('ISIC-ISW-2020 · Software Empresarial');

        $component->call('startRename', $this->networks->id)->call('cancelRename')->assertSet('renamingId', null);
    });

    it('desactiva y activa una especialidad aunque tenga materias', function () {
        $component = planPage($this->admin, $this->plan)->call('openSpecialties');

        $component->call('toggleSpecialty', $this->software->id);
        expect($this->software->fresh()->is_active)->toBeFalse()
            ->and(lastToastOf($component)['title'])->toBe('Especialidad desactivada');

        $component->call('toggleSpecialty', $this->software->id);
        expect($this->software->fresh()->is_active)->toBeTrue()
            ->and(lastToastOf($component)['title'])->toBe('Especialidad activada');
    });

    it('quita una especialidad sin materias; la que tiene materias muestra Quitar deshabilitado con su motivo y, pedido directo, lo rechaza', function () {
        $component = planPage($this->admin, $this->plan)->call('openSpecialties');
        $page = domOf($component);
        $row = $page->querySelector('[data-specialty="ISIC-ISW-2020"]');
        $blocked = collect($row->querySelectorAll('button'))->first(fn ($button): bool => textOf($button) === 'Quitar');
        $reason = $page->getElementById($blocked->getAttribute('aria-describedby'));

        // El motivo se ve escrito en el renglón, no solo en un tooltip, y el botón lo enlaza.
        expect($blocked->hasAttribute('disabled'))->toBeTrue()
            ->and($row->querySelector('[data-tip]'))->toBeNull()
            ->and($row->contains($reason))->toBeTrue()
            ->and(textOf($reason))->toBe('Tiene 1 materia en la retícula; una especialidad con materias no se quita.');

        $component->call('removeSpecialty', $this->software->id);
        expect($this->software->fresh()->trashed())->toBeFalse()
            ->and(lastToastOf($component))->type->toBe('error')->title->toBe('No se pudo quitar ISIC-ISW-2020')
            ->description->toBe('Tiene 1 materia en la retícula; una especialidad con materias no se quita.');

        $component->call('removeSpecialty', $this->networks->id)->assertDontSee('ISIC-RCS-2020');
        expect($this->networks->fresh()->trashed())->toBeTrue()
            ->and(lastToastOf($component))->type->toBe('success')->title->toBe('Especialidad quitada');
    });

    it('una especialidad de otro plan, quitada o con un id que no es uuid no existe', function (string $method, Closure $id) {
        $component = planPage($this->admin, $this->plan)->call('openSpecialties');
        $id = $id($this);

        $component->call($method, $id)->assertNotFound();

        expect(Activity::where('subject_type', (new Specialty)->getMorphClass())->count())->toBe(0);
    })->with(function () {
        $ids = [
            'de otro plan' => fn () => Specialty::factory()->create()->id,
            'quitada' => fn ($test) => tap(Specialty::factory()->for($test->plan)->create())->delete()->id,
            'no uuid' => fn () => 'no-es-uuid',
        ];
        $cases = [];

        foreach (['startRename', 'toggleSpecialty', 'removeSpecialty'] as $method) {
            foreach ($ids as $label => $id) {
                $cases["{$method}, {$label}"] = [$method, $id];
            }
        }

        return $cases;
    });
});

describe('otros roles e invitados', function () {
    beforeEach(fn () => planFixture($this));

    $roles = [
        'subdirección' => [Role::Subdireccion, []],
        'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
        'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)]],
        'docente' => [Role::Docente, []],
    ];

    it('reciben 403 en la página', function (Role $role, array $scope) {
        $user = User::factory()->withRole($role, ['campus_id' => Campus::factory()->create()->id, ...$scope])->create();

        $this->actingAs($user)->get(route('academic.plans.show', $this->plan))->assertForbidden();
    })->with($roles);

    it('reciben 403 en cada método del componente aunque lo llamen directo con una página que abrió el admin', function (string $method, Closure $arguments) {
        $user = User::factory()->withRole(Role::Coordinador, ['campus_id' => Campus::factory()->create()->id, 'career_id' => $this->career->id])->create();
        $component = planPage($this->admin, $this->plan)
            ->call('openSpecialties')
            ->call('startRename', $this->networks->id)
            ->set(['code' => 'NUEVA-1', 'name' => 'Nueva', 'renamedName' => 'Otro nombre']);

        $this->actingAs($user);
        $component->call($method, ...$arguments($this))->assertForbidden();

        expect($this->plan->specialties()->count())->toBe(2)
            ->and($this->networks->fresh())->name->toBe('Redes y Ciberseguridad')->is_active->toBeTrue()->trashed()->toBeFalse()
            ->and(Activity::where('subject_type', (new Specialty)->getMorphClass())->count())->toBe(0);
    })->with([
        'ver una materia' => ['select', fn ($test) => [$test->integralRow->id]],
        'resaltar una cadena' => ['focusChain', fn () => [0]],
        'quitar el resaltado' => ['clearFocus', fn () => []],
        'abrir las especialidades' => ['openSpecialties', fn () => []],
        'crear una especialidad' => ['createSpecialty', fn () => []],
        'empezar a renombrar' => ['startRename', fn ($test) => [$test->networks->id]],
        'cancelar el renombre' => ['cancelRename', fn () => []],
        'guardar el nombre' => ['saveRename', fn () => []],
        'activar o desactivar' => ['toggleSpecialty', fn ($test) => [$test->networks->id]],
        'quitar' => ['removeSpecialty', fn ($test) => [$test->networks->id]],
    ]);

    it('reciben 403 si abren el panel por la propiedad con una página que abrió el admin', function () {
        $user = User::factory()->withRole(Role::Coordinador, ['campus_id' => Campus::factory()->create()->id, 'career_id' => $this->career->id])->create();
        $component = planPage($this->admin, $this->plan)->call('openSpecialties')->set('showDrawer', false);

        $this->actingAs($user);
        $component->set('showDrawer', true)->assertForbidden();
    });

    it('un invitado va al login', function () {
        $this->get(route('academic.plans.show', $this->plan))->assertRedirect('/login');
    });
});
