<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\StudyPlanSubjectPending;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| «Por validar» (D18 y D19 de la migración del SII; maqueta B elegida el 5 de noviembre de 2026): las materias que la
| migración no pudo poner en la retícula de un plan porque no traían semestre. En la pantalla del plan, el segmentado
| «Retícula | Por validar (N)»; la bandeja de pendientes a la izquierda de la retícula, con búsqueda; elegir una
| muestra «Colocar aquí» en cada semestre, y el panel lateral confirma con el semestre, la carga del semestre y
| «Agregar a la retícula» (ResolvePendingPlacement). En la lista de planes, «N por validar» y el filtro «Por validar».
| Solo el jefe del Departamento de Servicios Escolares (D19) y el admin: `academic.placement.validate` sobre la unidad
| SERVICIOS-ESCOLARES. Ni un coordinador ni el jefe de un departamento académico ven la pestaña ni resuelven.
*/

/**
 * Un plan de cuatro semestres con tres pendientes (una de especialidad), uno resuelto y uno de otro plan; las
 * personas: el admin, el jefe de Servicios Escolares, el jefe de un departamento académico y un coordinador.
 */
function pendingFixture(object $test): void
{
    $test->services = OrgUnit::factory()->create(['code' => OrgTree::SCHOOL_SERVICES, 'name' => 'Departamento de Servicios Escolares', 'kind' => OrgUnitKind::Departamento]);
    $test->department = Department::factory()->create(['name' => 'Ciencias Básicas']);
    $test->career = Career::factory()->create(['code' => 'IIND', 'name' => 'Ingeniería Industrial', 'department_id' => $test->department->id]);
    $test->plan = StudyPlan::factory()->for($test->career)->create(['code' => 'IIND-2004', 'name' => 'Ingeniería Industrial', 'version' => '2004', 'semesters_count' => 4]);
    $test->otherPlan = StudyPlan::factory()->for($test->career)->create(['code' => 'IIND-2010', 'name' => 'Ingeniería Industrial 2010', 'semesters_count' => 9]);
    $test->idlePlan = StudyPlan::factory()->for($test->career)->create(['code' => 'IIND-2024', 'name' => 'Ingeniería Industrial 2024']);
    $test->quality = Specialty::factory()->for($test->plan)->create(['code' => 'IIND-CAL', 'name' => 'Calidad']);

    $subject = fn (string $code, string $name, int $theory, int $practice, int $credits): Subject => Subject::factory()->for($test->department)->create([
        'code' => $code, 'name' => $name, 'theory_hours' => $theory, 'practice_hours' => $practice, 'credits' => $credits,
    ]);

    placeInPlan($test->plan, $subject('ACF-0901', 'Cálculo Diferencial', 3, 2, 5), 3);
    placeInPlan($test->plan, $subject('AEF-1024', 'Física General', 2, 2, 4), 3);
    placeInPlan($test->plan, $subject('IND-1010', 'Estudio del Trabajo', 3, 2, 5), 3, $test->quality);

    $pending = fn (StudyPlan $plan, Subject $subject, string $grid, ?Specialty $specialty = null): StudyPlanSubjectPending => StudyPlanSubjectPending::factory()
        ->for($plan)->for($subject, 'subject')->create(['sii_grid' => $grid, 'specialty_id' => $specialty?->id]);

    $test->modern = $pending($test->plan, $subject('AE22', 'Física Moderna', 3, 2, 5), '3/5');
    $test->drawing = $pending($test->plan, $subject('AE9', 'Dibujo Electrónico', 2, 2, 4), '5/5');
    $test->seminar = $pending($test->plan, $subject('AE58', 'Seminario de Titulación', 0, 4, 4), '1/5', $test->quality);
    $pending($test->plan, $subject('AE1', 'Ya Resuelta', 2, 2, 4), '5/5')->forceFill(['resolved_at' => now()])->save();
    $pending($test->otherPlan, $subject('AE80', 'Desarrollo Sustentable', 3, 2, 5), '2/5');

    $campus = Campus::factory()->create();
    $test->admin = User::factory()->admin()->create();
    $test->servicesHead = User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => $test->services->id])->create(['name' => 'Sara Escolar']);
    $test->academicHead = User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => $test->department->id])->create();
    $test->coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $campus->id, 'career_id' => $test->career->id])->create();
}

function pendingTab(User $user, StudyPlan $plan): Testable
{
    return Livewire::actingAs($user)->test('academic::components.pending-placements', ['planId' => $plan->id]);
}

function seesPlansEntry(User $user): bool
{
    test()->actingAs($user);

    return collect(app(Navigation::class)->visibleTo(app(Actor::class)))
        ->contains(fn (NavigationItem $item): bool => $item->label === 'Planes de estudio');
}

beforeEach(fn () => pendingFixture($this));

describe('pantalla del plan', function () {
    it('el jefe de Servicios Escolares y el admin ven el segmentado con cuántas materias hay por validar', function (string $who) {
        $page = domOf($this->actingAs($this->{$who})->get(route('academic.plans.show', $this->plan))->assertOk());

        expect(textOf($page->querySelector('[data-testid="view-grid"]')))->toBe('Retícula')
            ->and(textOf($page->querySelector('[data-testid="view-pending"]')))->toBe('Por validar 3')
            ->and($page->querySelector('[data-testid="view-grid"]')->getAttribute('aria-pressed'))->toBe('true')
            ->and($page->querySelector('[data-testid="pending-tray"]'))->toBeNull();
    })->with(['servicesHead', 'admin']);

    it('«Por validar» muestra la bandeja con cada materia: nombre, clave, horas y créditos y su retícula del SII', function () {
        $page = domOf($this->actingAs($this->servicesHead)->get(route('academic.plans.show', [$this->plan, 'vista' => 'por-validar']))->assertOk());
        $chips = array_map(textOf(...), iterator_to_array($page->querySelectorAll('[data-pending]')));

        expect($page->querySelector('[data-testid="view-pending"]')->getAttribute('aria-pressed'))->toBe('true')
            ->and(textOf($page->querySelector('[data-testid="pending-tray"] h2')))->toBe('Por validar 3')
            ->and($chips)->toBe([
                'Física Moderna AE22 · 3-2-5 Retícula del SII 3/5',
                'Seminario de Titulación AE58 · 0-4-4 Retícula del SII 1/5',
                'Dibujo Electrónico AE9 · 2-2-4 Retícula del SII 5/5',
            ])
            ->and(textOf($page->querySelector('[data-testid="pending-grid"] [data-semester="3"]')))->toContain('Cálculo Diferencial', 'Física General')
            ->and($page->querySelector('[data-place-semester]'))->toBeNull();
    });

    it('busca en la bandeja por clave o nombre', function () {
        $tab = pendingTab($this->servicesHead, $this->plan)->set('search', 'moderna');

        expect(array_map(fn ($chip) => $chip->getAttribute('data-pending'), iterator_to_array(domOf($tab)->querySelectorAll('[data-pending]'))))->toBe(['AE22']);
    });

    it('elegir una materia muestra «Colocar aquí» en cada semestre y abre el panel con el semestre y su carga', function () {
        $tab = pendingTab($this->servicesHead, $this->plan)->call('pick', $this->modern->id);
        $dom = domOf($tab);

        expect($dom->querySelector('[data-pending="AE22"]')->getAttribute('aria-pressed'))->toBe('true')
            ->and(array_map(fn ($button) => textOf($button), iterator_to_array($dom->querySelectorAll('[data-place-semester]'))))->toBe(array_fill(0, 4, 'Colocar aquí'))
            ->and($dom->querySelector('[data-place-semester="3"]')->getAttribute('aria-label'))->toBe('Colocar Física Moderna en el semestre 3');

        $tab->call('openPlace', 3)->assertSet('showDrawer', true)->assertSet('semester', '3');
        $drawer = domOf($tab)->querySelector('[data-testid="pending-drawer"]');

        expect(textOf($drawer->querySelector('h2')))->toBe('Física Moderna')
            ->and(textOf($drawer->querySelector('[data-testid="pending-facts"]')))->toBe('Créditos 5 Horas T-P 3-2 Especialidad Ninguna Retícula SII 3/5')
            ->and(textOf($drawer->querySelector('[data-testid="pending-load"]')))->toBe('Semestre 3: 2 materias · 9 cr → 14 cr');

        $tab->set('semester', '1');

        expect(textOf(domOf($tab)->querySelector('[data-testid="pending-load"]')))->toBe('Semestre 1: 0 materias · 0 cr → 5 cr');
    });

    it('la carga de una materia de especialidad cuenta el tronco común y esa especialidad', function () {
        $tab = pendingTab($this->servicesHead, $this->plan)->call('pick', $this->seminar->id)->call('openPlace', 3);
        $drawer = domOf($tab)->querySelector('[data-testid="pending-drawer"]');

        expect(textOf($drawer->querySelector('[data-testid="pending-facts"]')))->toContain('Especialidad Calidad')
            ->and(textOf($drawer->querySelector('[data-testid="pending-load"]')))->toBe('Semestre 3: 3 materias · 14 cr → 18 cr');
    });

    it('«Agregar a la retícula» la pone en el semestre, la quita de la bandeja y avisa', function () {
        $tab = pendingTab($this->servicesHead, $this->plan)->call('pick', $this->modern->id)->call('openPlace', 3)->set('semester', '2')->call('place');

        $placement = StudyPlanSubject::where('study_plan_id', $this->plan->id)->where('subject_id', $this->modern->subject_id)->sole();
        $dom = domOf($tab);

        expect($placement->semester)->toBe(2)
            ->and($this->modern->fresh())->resolved_at->not->toBeNull()->resolved_by->toBe($this->servicesHead->id)
            ->and(lastToastOf($tab))->toMatchArray(['type' => 'success', 'title' => '«Física Moderna» agregada al semestre 2'])
            ->and($dom->querySelector('[data-pending="AE22"]'))->toBeNull()
            ->and(textOf($dom->querySelector('[data-testid="pending-grid"] [data-semester="2"]')))->toContain('Física Moderna')
            ->and($dom->querySelector('[data-testid="pending-grid"] [data-subject="AE22"]')->hasAttribute('data-new'))->toBeTrue()
            ->and($dom->querySelector('[data-place-semester]'))->toBeNull();
        $tab->assertSet('showDrawer', false)->assertSet('pickedId', null)->assertDispatched('pending-placed');
    });

    it('el segmentado y los datos del plan se actualizan al colocar una materia', function () {
        $page = Livewire::actingAs($this->servicesHead)->withQueryParams(['vista' => 'por-validar'])->test('academic::pages.plan', ['plan' => $this->plan->id]);
        pendingTab($this->servicesHead, $this->plan)->call('pick', $this->drawing->id)->call('openPlace', 1)->call('place');

        $page->dispatch('pending-placed');
        $dom = domOf($page);

        expect(textOf($dom->querySelector('[data-testid="view-pending"]')))->toBe('Por validar 2')
            ->and(textOf($dom->querySelector('[data-testid="plan-facts"]')))->toContain('Materias 4');
    });

    it('los errores de la Action se ven en el panel', function () {
        $tab = pendingTab($this->servicesHead, $this->plan)->call('pick', $this->modern->id)->call('openPlace', 3);

        $tab->set('semester', '7')->call('place')->assertHasErrors('semester');
        expect($tab->errors()->get('semester'))->toBe(['El semestre debe ser de 1 a 4: el plan tiene 4 semestres.']);

        $this->plan->update(['is_active' => false]);
        $tab->set('semester', '3')->call('place')->assertHasErrors('pending');
        expect($tab->errors()->get('pending'))->toBe(['El plan IIND-2004 está inactivo: actívalo antes de validar sus materias.']);

        expect(textOf(domOf($tab)->querySelector('[data-testid="pending-error"]')))->toBe('El plan IIND-2004 está inactivo: actívalo antes de validar sus materias.')
            ->and($this->modern->fresh()->resolved_at)->toBeNull();
        $tab->assertSet('showDrawer', true);
    });

    it('si otra sesión ya la resolvió, lo dice y la quita de la bandeja', function () {
        $tab = pendingTab($this->servicesHead, $this->plan)->call('pick', $this->modern->id)->call('openPlace', 3);
        $this->modern->forceFill(['resolved_at' => now()])->save();

        $tab->call('place');

        expect(lastToastOf($tab))->toMatchArray(['type' => 'info', 'title' => 'Esa materia ya se validó.'])
            ->and(domOf($tab)->querySelector('[data-pending="AE22"]'))->toBeNull();
        $tab->assertSet('showDrawer', false)->assertSet('pickedId', null);
    });

    it('un plan sin pendientes dice que no hay materias por validar', function () {
        $page = domOf($this->actingAs($this->servicesHead)->get(route('academic.plans.show', [$this->idlePlan, 'vista' => 'por-validar']))->assertOk());

        expect(textOf($page->querySelector('[data-testid="pending-empty"]')))->toBe('No hay materias por validar en este plan')
            ->and(textOf($page->querySelector('[data-testid="view-pending"]')))->toBe('Por validar');
    });

    it('un pendiente de otro plan o ya resuelto no se elige', function () {
        $done = StudyPlanSubjectPending::where('study_plan_id', $this->plan->id)->whereNotNull('resolved_at')->sole();

        pendingTab($this->servicesHead, $this->plan)->call('pick', $done->id)->assertNotFound();
        pendingTab($this->servicesHead, $this->plan)->call('pick', StudyPlanSubjectPending::where('study_plan_id', $this->otherPlan->id)->value('id'))->assertNotFound();
        pendingTab($this->servicesHead, $this->plan)->call('openPlace', 2)->assertSet('showDrawer', false);
    });

    it('para el jefe de Servicios Escolares la retícula es de solo lectura', function () {
        $page = Livewire::actingAs($this->servicesHead)->test('academic::pages.plan', ['plan' => $this->plan->id]);
        $dom = domOf($page);
        $row = StudyPlanSubject::where('study_plan_id', $this->plan->id)->firstOrFail();

        expect($dom->querySelector('[data-testid="open-specialties"]')->hasAttribute('disabled'))->toBeTrue()
            ->and($dom->querySelector('[data-add-semester="1"]')->hasAttribute('disabled'))->toBeTrue();

        $page->call('select', $row->id)->assertSet('showDrawer', false)->assertSet('selectedId', $row->id);
        expect(textOf(domOf($page)->querySelector('[data-testid="chain-info"]')))->not->toBe('');

        $page->call('clearFocus')->assertSet('selectedId', null);
        $page->call('openSpecialties')->assertForbidden();
    });
});

describe('alcance', function () {
    it('el coordinador y el jefe de un departamento académico no entran a la pantalla del plan ni a la lista', function (string $who) {
        $this->actingAs($this->{$who})->get(route('academic.plans.show', [$this->plan, 'vista' => 'por-validar']))->assertForbidden();
        $this->actingAs($this->{$who})->get(route('academic.plans'))->assertForbidden();

        expect(seesPlansEntry($this->{$who}))->toBeFalse();
    })->with(['coordinator', 'academicHead']);

    it('el coordinador y el jefe de un departamento académico no abren la bandeja', function (string $who) {
        pendingTab($this->{$who}, $this->plan)->assertForbidden();
    })->with(['coordinator', 'academicHead']);

    it('el coordinador y el jefe de un departamento académico no resuelven, aunque llamen con una página que abrió el jefe de Servicios Escolares', function (string $who, string $method) {
        $tab = pendingTab($this->servicesHead, $this->plan)->call('pick', $this->modern->id)->call('openPlace', 3);

        $this->actingAs($this->{$who});
        $tab->call($method, ...match ($method) {
            'pick' => [$this->drawing->id],
            'openPlace' => [2],
            default => [],
        })->assertForbidden();

        expect($this->modern->fresh()->resolved_at)->toBeNull()
            ->and(StudyPlanSubject::where('subject_id', $this->modern->subject_id)->exists())->toBeFalse();
    })->with(['coordinator', 'academicHead'])->with(['pick', 'openPlace', 'place']);

    it('un jefe de Servicios Escolares cuyo nombramiento terminó ya no entra', function () {
        $this->servicesHead->roleAssignments()->update(['valid_to' => today()->subDay()]);

        $this->actingAs($this->servicesHead->fresh())->get(route('academic.plans.show', $this->plan))->assertForbidden();
    });

    it('el jefe de Servicios Escolares ve Planes de estudio en el menú', function () {
        expect(seesPlansEntry($this->servicesHead))->toBeTrue();
    });
});

describe('lista de planes', function () {
    it('muestra «N por validar» con enlace a la pestaña y el filtro «Por validar» con cuántos planes', function (string $who) {
        $page = domOf($this->actingAs($this->{$who})->get(route('academic.plans'))->assertOk());
        $badge = $page->querySelector('table [data-pending-plan="IIND-2004"]');

        expect(textOf($badge))->toBe('3 por validar')
            ->and($badge->getAttribute('href'))->toBe(route('academic.plans.show', [$this->plan, 'vista' => 'por-validar']))
            ->and(textOf($page->querySelector('table [data-pending-plan="IIND-2010"]')))->toBe('1 por validar')
            ->and($page->querySelector('[data-pending-plan="IIND-2024"]'))->toBeNull()
            ->and(textOf($page->querySelector('[data-testid="plan-cards"] [data-pending-plan="IIND-2004"]')))->toBe('3 por validar')
            ->and(textOf($page->querySelector('[data-testid="filter-pending"]')))->toBe('Por validar 2')
            ->and($page->querySelector('[data-testid="filter-pending"]')->getAttribute('aria-pressed'))->toBe('false');
    })->with(['servicesHead', 'admin']);

    it('el filtro deja solo los planes con materias por validar', function () {
        $page = Livewire::actingAs($this->servicesHead)->test('academic::pages.plans')->call('togglePending');

        $page->assertSet('pendingOnly', true)
            ->assertViewHas('plans', fn ($plans): bool => $plans->pluck('code')->all() === ['IIND-2004', 'IIND-2010']);
        expect(domOf($page)->querySelector('[data-testid="filter-pending"]')->getAttribute('aria-pressed'))->toBe('true');
    });

    it('para el jefe de Servicios Escolares dar de alta, editar y activar quedan deshabilitados con su motivo', function () {
        $dom = domOf($this->actingAs($this->servicesHead)->get(route('academic.plans'))->assertOk());

        expect($dom->querySelector('[data-testid="create-plan"]')->hasAttribute('disabled'))->toBeTrue()
            ->and($dom->querySelector('[data-testid="edit-IIND-2004"]')->hasAttribute('disabled'))->toBeTrue()
            ->and(textOf($dom->querySelector('[data-testid="plan-cards"]')))->toContain('Solo el administrador activa o desactiva planes.');

        Livewire::actingAs($this->servicesHead)->test('academic::pages.plans')->call('create')->assertForbidden();
    });
});
