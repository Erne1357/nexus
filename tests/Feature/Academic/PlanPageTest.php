<?php

use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\SubjectPrerequisite;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use Database\Seeders\DemoSeeder;
use Dom\Element;
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

/**
 * Las opciones de un select, con « [deshabilitada]» en las que no se pueden elegir.
 *
 * @return list<string>
 */
function optionLabelsOf(?Element $select): array
{
    return array_map(
        fn (Element $option): string => textOf($option).($option->hasAttribute('disabled') ? ' [deshabilitada]' : ''),
        iterator_to_array($select?->querySelectorAll('option') ?? []),
    );
}

function placementActivitiesCount(): int
{
    return Activity::where('subject_type', (new StudyPlanSubject)->getMorphClass())->count();
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
            ->and(textOf($page->querySelector('[data-testid="placement-section"]')))->toContain('Lugar en la retícula', 'Semestre', 'Especialidad', 'Obligatoria', 'Guardar lugar')
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
        $component = planPage($this->admin, $this->plan)->call('select', $row->id)
            ->assertSet('semester', '4')
            ->assertSet('specialty_id', $this->software->id)
            ->assertSet('is_required', true);
        $page = domOf($component);

        expect(textOf($page->querySelector('[data-testid="requires-section"]')))->toContain('No pide ninguna materia.')
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

describe('lugar en la retícula', function () {
    beforeEach(fn () => planFixture($this));

    it('el lugar de la materia es un formulario con los semestres del plan y sus especialidades; una inactiva no se elige', function () {
        $this->networks->update(['is_active' => false]);
        $component = planPage($this->admin, $this->plan)->call('select', $this->integralRow->id)
            ->assertSet('semester', '2')
            ->assertSet('specialty_id', '')
            ->assertSet('is_required', true);
        $page = domOf($component);

        expect(optionLabelsOf($page->querySelector('[data-testid="placement-semester"]')))->toBe(['1', '2', '3', '4'])
            ->and(optionLabelsOf($page->querySelector('[data-testid="placement-specialty"]')))
            ->toBe(['Ninguna (tronco común)', 'Ingeniería de Software', 'Redes y Ciberseguridad (inactiva) [deshabilitada]'])
            ->and(textOf($page->querySelector('[data-testid="subject-panel"] footer')))->toBe('Cada cambio queda en la bitácora. Quitar de la retícula…');
    });

    it('la especialidad inactiva que ya tiene la materia se puede conservar', function () {
        $this->software->update(['is_active' => false]);
        $row = $this->plan->placements()->where('subject_id', $this->architecture->id)->sole();
        $page = domOf(planPage($this->admin, $this->plan)->call('select', $row->id));

        expect(optionLabelsOf($page->querySelector('[data-testid="placement-specialty"]')))
            ->toBe(['Ninguna (tronco común)', 'Ingeniería de Software (inactiva)', 'Redes y Ciberseguridad']);
    });

    it('guarda el lugar: mueve de semestre, cambia la especialidad y si es obligatoria, y la retícula lo muestra', function () {
        $component = planPage($this->admin, $this->plan)
            ->call('select', $this->integralRow->id)
            ->set(['semester' => '4', 'specialty_id' => $this->software->id, 'is_required' => false])
            ->call('savePlacement', $this->integralRow->id)
            ->assertHasNoErrors()
            ->assertSet('selectedId', $this->integralRow->id)
            ->assertSet('semester', '4');
        $page = domOf($component);

        expect($this->integralRow->fresh())->semester->toBe(4)->specialty_id->toBe($this->software->id)->is_required->toBeFalse()
            ->and(lastToastOf($component))->type->toBe('success')->title->toBe('Lugar guardado')->description->toBe('ACF-0902 Cálculo Integral queda en el semestre 4.')
            ->and(textOf($page->querySelector('[data-semester="4"]')))->toContain('Especialidad Ingeniería de Software', 'ACF-0902', 'ISD-2001')
            ->and(textOf($page->querySelector('[data-semester="2"]')))->not->toContain('ACF-0902')
            ->and(placementActivitiesCount())->toBe(1);
    });

    it('sin cambios lo dice y no escribe', function () {
        $component = planPage($this->admin, $this->plan)->call('select', $this->integralRow->id)->call('savePlacement', $this->integralRow->id);

        expect(lastToastOf($component))->type->toBe('info')->title->toBe('Sin cambios')->description->toBe('ACF-0902 Cálculo Integral ya estaba así.')
            ->and(placementActivitiesCount())->toBe(0);
    });

    it('muestra en su campo el error de un semestre fuera del plan o de una especialidad inactiva, y no escribe', function () {
        $this->networks->update(['is_active' => false]);

        planPage($this->admin, $this->plan)
            ->call('select', $this->integralRow->id)
            ->set('semester', '5')
            ->call('savePlacement', $this->integralRow->id)
            ->assertHasErrors('semester')
            ->assertSee('El semestre debe ser de 1 a 4: el plan tiene 4 semestres.')
            ->set(['semester' => '2', 'specialty_id' => $this->networks->id])
            ->call('savePlacement', $this->integralRow->id)
            ->assertHasErrors('specialty_id')
            ->assertSee('La especialidad ISIC-RCS-2020 está inactiva: actívala antes de ponerle materias.')
            ->set(['semester' => 'tres', 'specialty_id' => ''])
            ->call('savePlacement', $this->integralRow->id)
            ->assertHasErrors(['semester' => 'integer']);

        expect($this->integralRow->fresh())->semester->toBe(2)->specialty_id->toBeNull()
            ->and(placementActivitiesCount())->toBe(0);
    });

    it('mover o quitar una fila de otro plan, quitada o con un id que no es uuid no existe y no escribe (Review Focus 2)', function (string $method, Closure $id) {
        $id = $id($this);
        $component = planPage($this->admin, $this->plan)->call('select', $this->integralRow->id)->set('semester', '3');
        $trashed = StudyPlanSubject::onlyTrashed()->count();

        $component->call($method, $id)->assertNotFound();

        expect(StudyPlanSubject::onlyTrashed()->count())->toBe($trashed)
            ->and(StudyPlanSubject::where('semester', 3)->count())->toBe(2)
            ->and(placementActivitiesCount())->toBe(0);
    })->with(function () {
        $ids = [
            'de otro plan' => fn () => placeInPlan(StudyPlan::factory()->create(), Subject::factory()->create(), 2)->id,
            'quitada' => fn ($test) => tap(placeInPlan($test->plan, Subject::factory()->create(), 2))->delete()->id,
            'no uuid' => fn () => 'no-es-uuid',
        ];
        $cases = [];

        foreach (['savePlacement', 'removePlacement'] as $method) {
            foreach ($ids as $label => $id) {
                $cases["{$method}, {$label}"] = [$method, $id];
            }
        }

        return $cases;
    });

    it('quitar pide confirmar con la seriación que se va, y la quita con la fila', function () {
        $component = planPage($this->admin, $this->plan)
            ->call('select', $this->integralRow->id)
            ->call('confirmRemove')
            ->assertSet('showRemove', true);
        $modal = domOf($component)->querySelector('[data-testid="remove-modal"]');

        expect(textOf($modal))->toContain(
            'Quitar ACF-0902 de la retícula',
            'Cálculo Integral · semestre 2',
            'Se van con ella sus 3 seriaciones:',
            'Como materia pide ACF-0901 Cálculo Diferencial Seriación',
            'Como materia pide SCC-1017 Métodos Numéricos Correquisito',
            'Como requisito ACF-0904 la pide Cálculo Vectorial Seriación',
            'ACF-0904 se queda sin seriación.',
            'Cambia el avance de los alumnos con este plan. La materia sigue en el catálogo.',
        );

        $component->call('removePlacement', $this->integralRow->id)
            ->assertSet('selectedId', null)
            ->assertSet('showDrawer', false)
            ->assertSet('showRemove', false);
        $page = domOf($component);

        expect($this->integralRow->fresh()->trashed())->toBeTrue()
            ->and(SubjectPrerequisite::where('study_plan_id', $this->plan->id)->count())->toBe(0)
            ->and(lastToastOf($component))->type->toBe('success')->title->toBe('Materia quitada')
            ->description->toBe('ACF-0902 Cálculo Integral ya no está en la retícula ni sus 3 seriaciones.')
            ->and($page->querySelector('[data-subject="ACF-0902"]'))->toBeNull()
            ->and($page->querySelector('[data-testid="chain-legend"]'))->toBeNull()
            ->and(textOf($page->querySelector('[data-testid="plan-facts"]')))->toContain('Materias 5');
    });

    it('la confirmación de una materia sin seriación lo dice, con su especialidad', function () {
        $row = $this->plan->placements()->where('subject_id', $this->architecture->id)->sole();
        $component = planPage($this->admin, $this->plan)->call('select', $row->id)->call('confirmRemove');

        expect(textOf(domOf($component)->querySelector('[data-testid="remove-modal"]')))
            ->toContain('Arquitectura de Software · semestre 4 · especialidad Ingeniería de Software', 'No tiene seriación: no pide ninguna materia y ninguna la pide.');

        $component->call('removePlacement', $row->id);

        expect(lastToastOf($component)['description'])->toBe('ISD-2001 Arquitectura de Software ya no está en la retícula.');
    });

    it('la confirmación no se abre sin una materia elegida, y cerrar el panel la cierra', function () {
        planPage($this->admin, $this->plan)
            ->call('confirmRemove')
            ->assertSet('showRemove', false)
            ->set('showRemove', true)
            ->assertSet('showRemove', false)
            ->call('select', $this->integralRow->id)
            ->call('confirmRemove')
            ->set('showDrawer', false)
            ->assertSet('showRemove', false)
            ->assertSet('selectedId', null);
    });
});

describe('agregar materia', function () {
    beforeEach(function () {
        planFixture($this);
        $department = Department::factory()->create(['name' => 'Sistemas y Computación']);
        $catalog = fn (string $code, string $name, array $attributes = []): Subject => Subject::factory()->for($department)->create([
            'code' => $code, 'name' => $name, 'theory_hours' => 2, 'practice_hours' => 3, 'credits' => 5, ...$attributes,
        ]);
        $this->databases = $catalog('BDD-2402', 'Bases de Datos No Relacionales');
        $catalog('BDA-2101', 'Bases de Datos Avanzadas');
        $this->oldDatabases = $catalog('IFD-1005', 'Bases de Datos I', ['is_active' => false]);
        $catalog('TIB-1008', 'Bases de Datos Distribuidas')->delete();
        $this->fundamentals = $catalog('AEF-1031', 'Fundamentos de Base de Datos');
        placeInPlan($this->plan, $this->fundamentals, 3);
    });

    it('al pie de cada semestre hay «Agregar materia», que abre la búsqueda del catálogo en ese semestre', function () {
        $component = planPage($this->admin, $this->plan);
        $buttons = domOf($component)->querySelectorAll('[data-testid="plan-grid"] > section [data-add-semester]');

        expect(array_map(fn (Element $button): string => $button->getAttribute('data-add-semester').' '.textOf($button), iterator_to_array($buttons)))
            ->toBe(['1 Agregar materia', '2 Agregar materia', '3 Agregar materia', '4 Agregar materia']);

        $component->call('openAdd', 3)->assertSet('showAdd', true)->assertSet('addSemester', '3');
        $modal = domOf($component)->querySelector('[data-testid="add-modal"]');

        expect(textOf($modal))->toContain('Agregar materia al semestre 3', 'Busca entre las 10 materias del catálogo.', 'Escribe al menos dos letras de la clave o del nombre.')
            ->and(optionLabelsOf($modal->querySelector('[data-testid="add-semester"]')))->toBe(['1', '2', '3', '4'])
            ->and(optionLabelsOf($modal->querySelector('[data-testid="add-specialty"]')))->toBe(['Ninguna (tronco común)', 'Ingeniería de Software', 'Redes y Ciberseguridad']);
    });

    it('busca por clave o nombre con todas las palabras; las que ya están, las inactivas y las borradas se ven pero no se eligen', function () {
        $component = planPage($this->admin, $this->plan)->call('openAdd', 4)->set('addSearch', ' base  datos ');
        $rows = fn (): array => array_map(
            fn (Element $row): string => textOf($row).($row->hasAttribute('disabled') ? ' [deshabilitada]' : ''),
            iterator_to_array(domOf($component)->querySelectorAll('[data-testid="catalog"] button')),
        );

        expect($rows())->toBe([
            'BDA-2101 Bases de Datos Avanzadas Sistemas y Computación 2-3-5',
            'BDD-2402 Bases de Datos No Relacionales Sistemas y Computación 2-3-5',
            'AEF-1031 Fundamentos de Base de Datos Ya está en el semestre 3 2-3-5 [deshabilitada]',
            'IFD-1005 Bases de Datos I Inactiva en el catálogo 2-3-5 [deshabilitada]',
            'TIB-1008 Bases de Datos Distribuidas Borrada del catálogo 2-3-5 [deshabilitada]',
        ])->and(textOf(domOf($component)->querySelector('[data-testid="catalog-count"]')))->toBe('5 coincidencias');

        $component->set('addSearch', 'bdd-24');
        expect($rows())->toBe(['BDD-2402 Bases de Datos No Relacionales Sistemas y Computación 2-3-5'])
            ->and(textOf(domOf($component)->querySelector('[data-testid="catalog-count"]')))->toBe('1 coincidencia');

        $component->set('addSearch', 'b')->assertSee('Escribe al menos dos letras de la clave o del nombre.')
            ->set('addSearch', '100%')->assertSee('Ninguna materia coincide.');
    });

    it('dice cuántas coinciden cuando son más de las que muestra', function () {
        foreach (range(1, 10) as $i) {
            Subject::factory()->create(['name' => "Optativa de Investigación {$i}"]);
        }

        $page = domOf(planPage($this->admin, $this->plan)->call('openAdd', 1)->set('addSearch', 'optativa de inv'));

        expect($page->querySelectorAll('[data-testid="catalog"] button'))->toHaveCount(8)
            ->and(textOf($page->querySelector('[data-testid="catalog-count"]')))->toBe('10 coincidencias, se muestran 8');
    });

    it('agrega la materia elegida en el semestre, con su especialidad y si es obligatoria', function () {
        $component = planPage($this->admin, $this->plan)
            ->call('openAdd', 4)
            ->set('addSearch', 'BDD')
            ->call('pick', $this->databases->id)
            ->assertSet('addSubjectId', $this->databases->id)
            ->assertSet('addSubjectCode', 'BDD-2402');
        $page = domOf($component);

        expect($page->querySelector('[data-catalog="BDD-2402"]')->getAttribute('aria-pressed'))->toBe('true')
            ->and(textOf($page->querySelector('[data-testid="add-submit"]')))->toBe('Agregar BDD-2402');

        $component->set(['addSpecialtyId' => $this->software->id, 'addIsRequired' => false])
            ->call('place')
            ->assertHasNoErrors()
            ->assertSet('showAdd', false)
            ->assertSet('addSubjectId', null);

        expect($this->plan->placements()->where('subject_id', $this->databases->id)->sole())
            ->semester->toBe(4)->specialty_id->toBe($this->software->id)->is_required->toBeFalse()
            ->and(lastToastOf($component))->type->toBe('success')->title->toBe('Materia agregada')
            ->description->toBe('BDD-2402 Bases de Datos No Relacionales quedó en el semestre 4.')
            ->and(textOf(domOf($component)->querySelector('[data-semester="4"]')))->toContain('BDD-2402 Bases de Datos No Relacionales');
    });

    it('vuelve a poner una materia quitada de la retícula: restaura su fila (Review Focus 3)', function () {
        $row = placeInPlan($this->plan, $this->databases, 2);
        $row->delete();

        $component = planPage($this->admin, $this->plan)->call('openAdd', 3)->set('addSearch', 'BDD');

        expect(domOf($component)->querySelector('[data-catalog="BDD-2402"]')->hasAttribute('disabled'))->toBeFalse();

        $component->call('pick', $this->databases->id)->call('place')->assertHasNoErrors();

        expect($row->fresh())->trashed()->toBeFalse()->semester->toBe(3)
            ->and(StudyPlanSubject::withTrashed()->where('subject_id', $this->databases->id)->count())->toBe(1);
    });

    it('cambiar la búsqueda suelta la materia elegida', function () {
        planPage($this->admin, $this->plan)
            ->call('openAdd', 1)
            ->set('addSearch', 'BDD')
            ->call('pick', $this->databases->id)
            ->set('addSearch', 'BDA')
            ->assertSet('addSubjectId', null)
            ->assertSet('addSubjectCode', '');
    });

    it('sin materia elegida pide una; una que ya está, una inactiva, un semestre fuera del plan o una especialidad ajena dan su error, sin escribir', function () {
        $component = planPage($this->admin, $this->plan)
            ->call('openAdd', 2)
            ->call('place')
            ->assertHasErrors(['addSubjectId' => 'required'])
            ->assertSee('Elige una materia del catálogo.')
            ->call('pick', $this->fundamentals->id)
            ->call('place')
            ->assertHasErrors('addSubjectId')
            ->assertSee('AEF-1031 ya está en la retícula de este plan, en el semestre 3.')
            ->call('pick', $this->oldDatabases->id)
            ->call('place')
            ->assertSee('IFD-1005 está inactiva en el catálogo: actívala antes de ponerla en una retícula.')
            ->call('pick', $this->databases->id)
            ->set('addSemester', '5')
            ->call('place')
            ->assertHasErrors('addSemester')
            ->assertSee('El semestre debe ser de 1 a 4: el plan tiene 4 semestres.')
            ->set(['addSemester' => '2', 'addSpecialtyId' => Specialty::factory()->create()->id])
            ->call('place')
            ->assertHasErrors('addSpecialtyId')
            ->assertSee('La especialidad no es de este plan o se quitó.')
            ->set('addSpecialtyId', 'no-es-uuid')
            ->call('place')
            ->assertHasErrors('addSpecialtyId')
            ->assertSet('showAdd', true);

        expect($this->plan->placements()->count())->toBe(7)
            ->and(placementActivitiesCount())->toBe(0);
    });

    it('elegir una materia que no existe o con un id que no es uuid no existe', function (string $id) {
        planPage($this->admin, $this->plan)->call('openAdd', 1)->call('pick', $id)->assertNotFound();
    })->with(['no existe' => fakeKey(99), 'no uuid' => 'no-es-uuid']);

    it('un plan sin semestres ni materias ofrece agregar la primera, en el semestre 1', function () {
        $empty = StudyPlan::factory()->create(['semesters_count' => null]);
        $component = planPage($this->admin, $empty);

        expect(textOf(domOf($component)->querySelector('[data-testid="empty-grid"]')))
            ->toBe('Este plan todavía no tiene semestres ni materias en su retícula. Agregar materia');

        $component->call('openAdd', 1);
        expect(optionLabelsOf(domOf($component)->querySelector('[data-testid="add-semester"]')))->toBe(['1']);

        $component->set('addSearch', 'BDD')->call('pick', $this->databases->id)->call('place')->assertHasNoErrors();

        expect($empty->placements()->sole()->semester)->toBe(1)
            ->and(domOf($component)->querySelectorAll('[data-testid="plan-grid"] > section'))->toHaveCount(1);
    });

    it('sin semestres capturados ofrece los que tiene y uno más, hasta 12 (supuesto por confirmar)', function () {
        $this->plan->update(['semesters_count' => null]);

        $page = domOf(planPage($this->admin, $this->plan)->call('openAdd', 1));
        expect(optionLabelsOf($page->querySelector('[data-testid="add-semester"]')))->toBe(['1', '2', '3', '4', '5']);

        placeInPlan($this->plan, $this->databases, 12);
        $page = domOf(planPage($this->admin, $this->plan)->call('openAdd', 1));
        expect(optionLabelsOf($page->querySelector('[data-testid="add-semester"]')))->toBe(array_map('strval', range(1, 12)));
    });

    it('busca en el catálogo en un número fijo de consultas', function () {
        foreach (range(1, 30) as $i) {
            Subject::factory()->create(['name' => "Optativa {$i}"]);
        }

        $component = planPage($this->admin, $this->plan)->call('openAdd', 1);
        DB::enableQueryLog();
        $component->set('addSearch', 'optativa');
        $queries = count(DB::getQueryLog());

        // 7 medidas, constantes con el número de materias: el plan con su carrera (2), la retícula (3) y el
        // catálogo (2: cuántas tiene y las que coinciden, con su cuenta en la misma consulta).
        expect(domOf($component)->querySelectorAll('[data-testid="catalog"] button'))->toHaveCount(8)
            ->and($queries)->toBe(7);
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

    it('reciben 403 en cada método de la retícula aunque lo llamen directo con una página que abrió el admin', function (string $method, Closure $arguments) {
        $user = User::factory()->withRole(Role::Coordinador, ['campus_id' => Campus::factory()->create()->id, 'career_id' => $this->career->id])->create();
        $free = Subject::factory()->create();
        $component = planPage($this->admin, $this->plan)
            ->call('select', $this->integralRow->id)
            ->set('semester', '3')
            ->call('openAdd', 1)
            ->call('pick', $free->id);

        $this->actingAs($user);
        $component->call($method, ...$arguments($this, $free))->assertForbidden();

        expect($this->plan->placements()->count())->toBe(6)
            ->and($this->integralRow->fresh())->semester->toBe(2)->trashed()->toBeFalse()
            ->and(SubjectPrerequisite::count())->toBe(3)
            ->and(placementActivitiesCount())->toBe(0);
    })->with([
        'abrir «Agregar materia»' => ['openAdd', fn () => [2]],
        'elegir una materia del catálogo' => ['pick', fn ($test, Subject $free) => [$free->id]],
        'agregar la materia' => ['place', fn () => []],
        'guardar el lugar' => ['savePlacement', fn ($test) => [$test->integralRow->id]],
        'pedir quitarla' => ['confirmRemove', fn () => []],
        'quitarla de la retícula' => ['removePlacement', fn ($test) => [$test->integralRow->id]],
    ]);

    it('reciben 403 si abren «Agregar materia» o la confirmación de quitar por la propiedad', function (string $property) {
        $user = User::factory()->withRole(Role::Coordinador, ['campus_id' => Campus::factory()->create()->id, 'career_id' => $this->career->id])->create();
        $component = planPage($this->admin, $this->plan)->call('select', $this->integralRow->id);

        $this->actingAs($user);
        $component->set($property, true)->assertForbidden();
    })->with(['showAdd', 'showRemove']);

    it('un invitado va al login', function () {
        $this->get(route('academic.plans.show', $this->plan))->assertRedirect('/login');
    });
});
