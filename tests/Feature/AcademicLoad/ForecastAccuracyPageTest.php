<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Enums\ForecastEvaluationStatus;
use App\Modules\AcademicLoad\Jobs\RunForecastEvaluation;
use App\Modules\AcademicLoad\Models\ForecastEvaluation;
use App\Modules\AcademicLoad\Models\ForecastEvaluationRow;
use App\Modules\AcademicLoad\Rules\ForecastAccuracy;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\PermissionSet;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
| «Precisión de la proyección» (tarea 5.9c; DESIGN.md, «Precisión de la proyección»: variante B «Contra la meta»,
| elegida por el usuario el 17 de noviembre de 2026). Subdirección y el admin (DM-24; C3) ven cada carrera contra la
| meta del 15 % (DM-22), lanzan una evaluación de un periodo con kárdex (RequestForecastEvaluation, DM-28) y abren las
| materias de una carrera. Los demás roles reciben 403 y no ven la entrada del menú.
*/

beforeEach(function () {
    $this->subdireccion = User::factory()->withRole(Role::Subdireccion)->create();
    $this->c1 = Campus::factory()->create(['code' => 'K1', 'name' => 'Campus K1']);
    $this->c2 = Campus::factory()->create(['code' => 'K2', 'name' => 'Campus K2']);
    $this->basics = Department::factory()->create(['code' => 'DB', 'name' => 'Ciencias de Prueba']);
    $this->systems = Department::factory()->create(['code' => 'DS', 'name' => 'Sistemas de Prueba']);
    $this->isx = Career::factory()->create(['code' => 'ISX', 'name' => 'Ingeniería de Prueba', 'department_id' => $this->systems->id]);
    $this->lax = Career::factory()->create(['code' => 'LAX', 'name' => 'Licenciatura de Prueba', 'department_id' => $this->basics->id]);
    $this->calculus = Subject::factory()->create(['code' => 'PA-1', 'name' => 'Cálculo de Prueba', 'department_id' => $this->basics->id]);
    $this->programming = Subject::factory()->create(['code' => 'PB-2', 'name' => 'Programación de Prueba', 'department_id' => $this->systems->id]);
    $this->ethics = Subject::factory()->create(['code' => 'PC-3', 'name' => 'Ética de Prueba', 'department_id' => $this->basics->id]);
    $this->term = Term::factory()->create(['code' => '20261', 'name' => 'Ene-Jun 2026']);
});

/**
 * Una evaluación con sus filas y su `totals` con la forma de EvaluateForecast: por carrera (y sus campus), por campus y
 * por departamento de la materia, todo con ForecastAccuracy. Con `status` corriendo, solo las carreras de `done` van en
 * `by_career` y lo global queda nulo, como mientras guarda.
 *
 * @param  list<array{0: Career, 1: Campus, 2: Subject, 3: int, 4: int, 5?: int, 6?: string, 7?: ?int}>  $rows  carrera, campus, materia, proyectado, real, base, método y grupos abiertos
 * @param  array<string, mixed>  $attributes
 */
function precisionEvaluation(array $rows, array $attributes = [], ?array $done = null, int $outOfScope = 0): ForecastEvaluation
{
    $evaluation = ForecastEvaluation::factory()->create(['term_id' => test()->term->id, ...$attributes]);
    $saved = [];

    foreach ($rows as $row) {
        [$career, $campus, $subject, $projected, $actual] = $row;
        $saved[] = ForecastEvaluationRow::factory()->create([
            'evaluation_id' => $evaluation->id,
            'career_id' => $career->id,
            'campus_id' => $campus->id,
            'subject_id' => $subject->id,
            'eligible' => $row[5] ?? $projected,
            'projected' => $projected,
            'baseline' => $row[5] ?? $projected,
            'actual_students' => $actual,
            'suggested_groups' => (int) ceil($projected / 35),
            'actual_groups' => array_key_exists(7, $row) ? $row[7] : null,
            'forecast_method' => $row[6] ?? 'formula',
        ]);
    }

    $metrics = function (iterable $group): array {
        $group = collect($group);
        $result = ForecastAccuracy::of($group->map(fn (ForecastEvaluationRow $row): array => ['projected' => $row->projected, 'actual' => $row->actual_students, 'baseline' => $row->baseline]));

        return [
            'weighted_error' => $result->weightedError === null ? null : round($result->weightedError, 4),
            'baseline_error' => $result->baselineError === null ? null : round($result->baselineError, 4),
            'mean_absolute_error' => round($result->meanAbsoluteError, 4),
            'bias' => round($result->bias, 4),
            'within_share' => $result->withinShare === null ? null : round($result->withinShare, 4),
            'confidence' => $result->confidence()->value,
            'rows' => $group->count(),
        ];
    };
    $all = collect($saved);
    $byCareer = $all->groupBy('career_id')
        ->filter(fn ($group, string $careerId): bool => $done === null || in_array($careerId, array_map(fn (Career $career): string => $career->id, $done), true))
        ->map(fn ($group, string $careerId): array => ['career_id' => $careerId, ...$metrics($group), 'by_campus' => $group->groupBy('campus_id')->map(fn ($inCampus, string $campusId): array => ['campus_id' => $campusId, ...$metrics($inCampus)])->values()->all()])
        ->values()->all();
    $running = $done !== null;
    $departments = Subject::query()->pluck('department_id', 'id');

    $evaluation->forceFill(['totals' => [
        'overall' => $running ? null : $metrics($all),
        'by_career' => $byCareer,
        'by_campus' => $running ? null : $all->groupBy('campus_id')->map(fn ($group, string $campusId): array => ['campus_id' => $campusId, ...$metrics($group)])->values()->all(),
        'by_department' => $running ? null : $all->groupBy(fn (ForecastEvaluationRow $row): string => $departments[$row->subject_id])->map(fn ($group, string $departmentId): array => ['department_id' => $departmentId, ...$metrics($group)])->values()->all(),
        'careers' => ['done' => count($byCareer), 'total' => $all->pluck('career_id')->unique()->count()],
        'out_of_scope' => ['students' => $outOfScope, 'subjects' => $outOfScope > 0 ? 1 : 0],
    ]])->save();

    return $evaluation->fresh();
}

/**
 * ISX en K1 y K2 dentro de la meta (error 13.2 %; 12.5 % en K2 y 20 % en las materias de DS) y LAX en K1 fuera de ella
 * (error 30 %), con R1 peor en las dos; en todo el Tec, 16.7 %.
 */
function precisionTwoCareers(array $attributes = [], int $outOfScope = 0): ForecastEvaluation
{
    $t = test();

    return precisionEvaluation([
        [$t->isx, $t->c1, $t->calculus, 110, 100, 160, 'formula', 3],
        [$t->isx, $t->c1, $t->programming, 40, 50, 80, 'department_fallback', null],
        [$t->isx, $t->c2, $t->calculus, 35, 40, 60],
        [$t->lax, $t->c1, $t->calculus, 65, 50, 90],
        [$t->lax, $t->c1, $t->ethics, 0, 0, 10],
    ], $attributes, outOfScope: $outOfScope);
}

/**
 * @return list<string>
 */
function precisionMenuOf(User $user): array
{
    test()->actingAs($user);
    app()->forgetScopedInstances();

    return array_map(fn ($item): string => $item->label, app(Navigation::class)->visibleTo(app(Actor::class)));
}

describe('alcance (DM-24)', function () {
    it('Subdirección y el admin abren la pantalla y la ven en su menú', function () {
        precisionTwoCareers();

        foreach ([$this->subdireccion, User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)->get(route('academic-load.forecast-accuracy'))
                ->assertOk()
                ->assertSee('Precisión de la proyección')
                ->assertSee('Ene-Jun 2026');

            expect(precisionMenuOf($user))->toContain('Precisión de la proyección');
        }
    });

    it('el jefe, el coordinador, el jefe de proyecto docente y el docente reciben 403 y no ven la entrada', function (Closure $user) {
        $user = $user($this);

        $this->actingAs($user)->get(route('academic-load.forecast-accuracy'))->assertForbidden();

        expect(precisionMenuOf($user))->not->toContain('Precisión de la proyección');
    })->with([
        'jefe de departamento' => [fn ($test) => User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $test->systems->id])->create()],
        'coordinador' => [fn ($test) => User::factory()->withRole(Role::Coordinador, ['campus_id' => $test->c1->id, 'career_id' => $test->isx->id])->create()],
        'jefe de proyecto docente' => [fn ($test) => User::factory()->withRole(Role::JefeProyectoDocente, ['department_id' => $test->systems->id])->create()],
        'docente' => [fn () => User::factory()->withRole(Role::Docente)->create()],
    ]);

    it('quien solo ve el reporte tiene «Evaluar un periodo» deshabilitado y no puede lanzar', function () {
        $coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c1->id, 'career_id' => $this->isx->id])->create();
        PermissionSet::factory()->withPermissions([AcademicLoadPermission::ForecastEvaluationView])->assignedTo(Role::Coordinador)->create(['name' => 'Consulta de la precisión']);
        precisionTwoCareers();

        $page = Livewire::actingAs($coordinator)->test('academic-load::pages.forecast-accuracy')->assertOk();
        $button = domOf($page)->querySelector('[data-testid="open-evaluate"]');

        expect($button->hasAttribute('disabled'))->toBeTrue()
            ->and($button->getAttribute('title'))->toBe('No tienes permiso para evaluar la proyección.');

        $page->call('evaluate')->assertForbidden();
        Livewire::actingAs($coordinator)->test('academic-load::pages.forecast-accuracy')->call('openEvaluate')->assertForbidden();
    });

    it('sin el permiso de ver, cada acción llamada directo da 403', function (string $method, Closure $arguments) {
        $evaluation = precisionTwoCareers();
        $page = Livewire::actingAs($this->subdireccion)->test('academic-load::pages.forecast-accuracy');

        $this->actingAs(User::factory()->withRole(Role::Docente)->create());
        app()->forgetScopedInstances();

        $page->call($method, ...$arguments($this, $evaluation))->assertForbidden();
    })->with([
        'elegir una evaluación' => ['select', fn ($test, ForecastEvaluation $evaluation): array => [$evaluation->id]],
        'abrir las materias' => ['openCareer', fn ($test): array => [$test->isx->id]],
        'abrir «Evaluaciones»' => ['openEvaluations', fn (): array => []],
        'abrir «Evaluar un periodo»' => ['openEvaluate', fn (): array => []],
        'cerrar las materias' => ['closeSubjects', fn (): array => []],
    ]);
});

describe('la evaluación elegida', function () {
    it('abre la más reciente y, con ?evaluacion=, la que se pide', function () {
        $older = precisionTwoCareers(['created_at' => now()->subDay()]);
        $this->term = Term::factory()->create(['code' => '20253', 'name' => 'Ago-Dic 2025']);
        precisionTwoCareers();

        $this->actingAs($this->subdireccion)->get(route('academic-load.forecast-accuracy'))
            ->assertSeeInOrder(['data-testid="evaluation-strip"', 'Ago-Dic 2025'], false);

        $this->get(route('academic-load.forecast-accuracy', ['evaluacion' => $older->id]))
            ->assertSeeInOrder(['data-testid="evaluation-strip"', 'Ene-Jun 2026'], false);
    });

    it('DM-22: agrupa las carreras contra la meta del 15 % con su error y el de R1', function () {
        precisionTwoCareers();

        $page = domOf($this->actingAs($this->subdireccion)->get(route('academic-load.forecast-accuracy')));

        expect(textOf($page->querySelector('[data-testid="global-error"]')))->toContain('16.7')
            ->and(textOf($page->querySelector('[data-testid="group-strong"]')))->toContain('Sugerencia fuerte')->toContain('ISX')->not->toContain('LAX')
            ->and(textOf($page->querySelector('[data-testid="group-reference"]')))->toContain('Referencia')->toContain('LAX')
            ->and(textOf($page->querySelector('[data-testid="career-ISX"]')))->toContain('13.2')->toContain('R1')->toContain('57.9');
    });

    it('DM-22: el punto de Nexus sale de la confianza, no del error redondeado', function () {
        precisionEvaluation([[$this->isx, $this->c1, $this->calculus, 11497, 9997, 11497]]);

        $page = domOf($this->actingAs($this->subdireccion)->get(route('academic-load.forecast-accuracy')));
        $dot = $page->querySelector('[data-testid="career-ISX"] [data-nexus-dot]');

        expect(ForecastEvaluation::query()->sole()->totals['by_career'][0])
            ->weighted_error->toBe(0.15)
            ->confidence->toBe('reference')
            ->and($dot->getAttribute('class'))->toContain('bg-warning')->not->toContain('bg-success')
            ->and(textOf($page->querySelector('[data-testid="group-reference"]')))->toContain('ISX');
    });

    it('el filtro de campus muestra el error de cada carrera en ese campus', function () {
        precisionTwoCareers();

        $page = Livewire::actingAs($this->subdireccion)->test('academic-load::pages.forecast-accuracy')->set('campus', 'K2');

        expect(textOf(domOf($page)->querySelector('[data-testid="career-ISX"]')))->toContain('12.5')
            ->and(domOf($page)->querySelector('[data-testid="career-LAX"]'))->toBeNull();
    });

    it('el filtro de departamento deja en cada carrera solo las materias de ese departamento', function () {
        precisionTwoCareers();

        $page = Livewire::actingAs($this->subdireccion)->test('academic-load::pages.forecast-accuracy')->set('department', 'DS');

        expect(textOf(domOf($page)->querySelector('[data-testid="career-ISX"]')))->toContain('20.0')
            ->and(domOf($page)->querySelector('[data-testid="career-LAX"]'))->toBeNull();
    });

    it('la vista «Tabla» da lo mismo sin gráfica', function () {
        precisionTwoCareers();

        $page = Livewire::actingAs($this->subdireccion)->test('academic-load::pages.forecast-accuracy')->set('view', 'tabla');

        expect(textOf(domOf($page)->querySelector('[data-testid="accuracy-table"]')))->toContain('ISX')->toContain('LAX')->toContain('Referencia')
            ->and(domOf($page)->querySelector('[data-testid="accuracy-chart"]'))->toBeNull();
    });

    it('avisa cuántos alumnos de carreras o campus sin oferta no se evalúan', function () {
        precisionTwoCareers(outOfScope: 37);

        $this->actingAs($this->subdireccion)->get(route('academic-load.forecast-accuracy'))
            ->assertSeeText('37 alumnos de carreras o campus sin oferta no se evalúan.');
    });

    it('sin alumnos fuera del alcance no hay aviso', function () {
        precisionTwoCareers();

        $this->actingAs($this->subdireccion)->get(route('academic-load.forecast-accuracy'))
            ->assertDontSee('sin oferta no se evalúan');
    });

    it('una evaluación fallida dice su error', function () {
        ForecastEvaluation::factory()->failed('No se pudo reconstruir a los elegibles.')->create(['term_id' => $this->term->id]);

        $this->actingAs($this->subdireccion)->get(route('academic-load.forecast-accuracy'))
            ->assertSee('La evaluación no terminó.')
            ->assertSee('No se pudo reconstruir a los elegibles.');
    });

    it('sin evaluaciones invita a evaluar un periodo', function () {
        $this->actingAs($this->subdireccion)->get(route('academic-load.forecast-accuracy'))
            ->assertSee('Todavía no hay evaluaciones');
    });
});

describe('mientras corre', function () {
    it('consulta cada 2 s y pinta las carreras que ya terminaron', function () {
        precisionEvaluation([
            [$this->isx, $this->c1, $this->calculus, 110, 100],
            [$this->lax, $this->c1, $this->calculus, 65, 50],
        ], ['status' => ForecastEvaluationStatus::Running, 'finished_at' => null, 'progress' => 80, 'stage' => 'Guardando resultados'], done: [$this->isx]);

        $html = $this->actingAs($this->subdireccion)->get(route('academic-load.forecast-accuracy'))->getContent();
        $page = domOf($html);

        expect($html)->toContain('wire:poll.2s')
            ->and(textOf($page->querySelector('[data-testid="running-block"]')))->toContain('1 de 2')->toContain('Guardando resultados')
            ->and($page->querySelector('[data-testid="career-ISX"]'))->not->toBeNull()
            ->and($page->querySelector('[data-testid="career-LAX"]'))->toBeNull();
    });

    it('lista, ya no consulta', function () {
        precisionTwoCareers();

        expect($this->actingAs($this->subdireccion)->get(route('academic-load.forecast-accuracy'))->getContent())->not->toContain('wire:poll');
    });

    it('con la elegida lista y otra corriendo, consulta cada 10 s', function () {
        $done = precisionTwoCareers();
        ForecastEvaluation::factory()->running()->create(['term_id' => Term::factory()->create(['code' => '20253'])->id]);

        $html = $this->actingAs($this->subdireccion)->get(route('academic-load.forecast-accuracy', ['evaluacion' => $done->id]))->getContent();

        expect($html)->toContain('wire:poll.10s')->not->toContain('wire:poll.2s');
    });

    it('una evaluación que no se movió en una hora se da por perdida: no hace consultar a la pantalla', function () {
        $stuck = ForecastEvaluation::factory()->running()->create(['term_id' => $this->term->id]);
        $stuck->forceFill(['updated_at' => now()->subSeconds(RunForecastEvaluation::STALE_AFTER + 60)])->saveQuietly();
        precisionTwoCareers();

        $this->actingAs($this->subdireccion);

        expect($this->get(route('academic-load.forecast-accuracy', ['evaluacion' => $stuck->id]))->getContent())->not->toContain('wire:poll')
            ->and($this->get(route('academic-load.forecast-accuracy'))->getContent())->not->toContain('wire:poll');
    });
});

describe('materias de una carrera', function () {
    it('las ordena por error, con los grupos sugeridos contra los abiertos y el método', function () {
        $evaluation = precisionTwoCareers();

        $page = Livewire::actingAs($this->subdireccion)->test('academic-load::pages.forecast-accuracy')->call('openCareer', $this->isx->id);
        $rows = domOf($page)->querySelectorAll('[data-testid="subject-row"]');

        expect($page->get('showSubjects'))->toBeTrue()
            ->and(array_map(fn ($row): string => $row->getAttribute('data-subject'), iterator_to_array($rows)))->toBe(['PB-2', 'PA-1', 'PA-1'])
            ->and(textOf($rows[0]))->toContain('Promedio del departamento')->toContain('grupos 2 → —')
            ->and(textOf($rows[2]))->toContain('grupos 4 → 3')
            ->and(domOf($page)->querySelector('[data-testid="subjects-scatter"]'))->not->toBeNull()
            ->and($evaluation->id)->toBe($page->get('evaluation'));
    });

    it('DM-21: arriba lo que se proyectó sin alumnos, al final lo que no cuenta (proyectado y real en cero)', function () {
        precisionEvaluation([
            [$this->lax, $this->c1, $this->calculus, 65, 50],
            [$this->lax, $this->c1, $this->ethics, 0, 0],
            [$this->lax, $this->c1, $this->programming, 12, 0],
        ]);

        $page = Livewire::actingAs($this->subdireccion)->test('academic-load::pages.forecast-accuracy')->call('openCareer', $this->lax->id);
        $rows = domOf($page)->querySelectorAll('[data-testid="subject-row"]');

        expect(array_map(fn ($row): string => $row->getAttribute('data-subject'), iterator_to_array($rows)))->toBe(['PB-2', 'PA-1', 'PC-3'])
            ->and(textOf($rows[0]))->toContain('sin alumnos')->toContain('+12')
            ->and($rows[0]->getAttribute('data-out'))->toBe('1')
            ->and(textOf($rows[2]))->toContain('0 de 0')->toContain('—')
            ->and($rows[2]->getAttribute('data-out'))->toBe('0')
            ->and(textOf(domOf($page)->querySelector('[data-testid="subjects-scatter"]')->parentNode))->toContain('2 materias');
    });

    it('con el filtro de departamento solo trae las materias de ese departamento', function () {
        precisionTwoCareers();

        $page = Livewire::actingAs($this->subdireccion)->test('academic-load::pages.forecast-accuracy')
            ->set('department', 'DB')
            ->call('openCareer', $this->isx->id);

        expect(array_map(fn ($row): string => $row->getAttribute('data-subject'), iterator_to_array(domOf($page)->querySelectorAll('[data-testid="subject-row"]'))))->toBe(['PA-1', 'PA-1'])
            ->and(textOf(domOf($page)->querySelector('[data-testid="subjects-panel"] header')))->toContain('Materias de DB');
    });

    it('el campus del drawer deja las materias y las cifras de ese campus', function () {
        precisionTwoCareers();

        $page = Livewire::actingAs($this->subdireccion)->test('academic-load::pages.forecast-accuracy')->call('openCareer', $this->isx->id);

        expect(domOf($page)->querySelector('[data-testid="subjects-campus"]'))->not->toBeNull();

        $page->set('subjectCampus', 'K2');

        expect(array_map(fn ($row): string => $row->getAttribute('data-subject'), iterator_to_array(domOf($page)->querySelectorAll('[data-testid="subject-row"]'))))->toBe(['PA-1'])
            ->and(textOf(domOf($page)->querySelector('[data-testid="subjects-facts"]')))->toContain('12.5');
    });

    it('una carrera que no está en la evaluación no abre el drawer', function () {
        precisionTwoCareers();
        $other = Career::factory()->create(['code' => 'OTX']);

        $page = Livewire::actingAs($this->subdireccion)->test('academic-load::pages.forecast-accuracy')->call('openCareer', $other->id);

        expect($page->get('showSubjects'))->toBeFalse()
            ->and($page->get('openedCareer'))->toBeNull()
            ->and(domOf($page)->querySelector('[data-testid="subjects-panel"]'))->toBeNull();
    });
});

describe('evaluar un periodo', function () {
    beforeEach(function () {
        $plan = StudyPlan::factory()->create(['career_id' => $this->isx->id]);
        placeInPlan($plan, $this->calculus);
        CareerCampus::factory()->create(['career_id' => $this->isx->id, 'campus_id' => $this->c1->id]);
        $student = Student::factory()->create(['study_plan_id' => $plan->id, 'career_id' => $this->isx->id, 'campus_id' => $this->c1->id, 'modality_id' => modalityOf('PRES')->id]);
        KardexRecord::factory()->for($student)->for($this->calculus)->create(['term_code' => '20261', 'result' => KardexResult::Cursando, 'grade' => null]);
    });

    it('Subdirección lanza la evaluación de un periodo con kárdex y la ve elegida', function () {
        Queue::fake();

        $page = Livewire::actingAs($this->subdireccion)->test('academic-load::pages.forecast-accuracy')
            ->call('openEvaluate')
            ->assertSee('Ene-Jun 2026')
            ->set('newModality', 'PRES')
            ->set('newTerm', $this->term->id)
            ->call('evaluate')
            ->assertHasNoErrors();
        $evaluation = ForecastEvaluation::query()->sole();

        expect($evaluation)
            ->status->toBe(ForecastEvaluationStatus::Queued)
            ->campus_id->toBeNull()
            ->requested_by->toBe($this->subdireccion->id)
            ->and($page->get('evaluation'))->toBe($evaluation->id)
            ->and($page->get('showEvaluate'))->toBeFalse()
            ->and(lastToastOf($page)['title'])->toBe('Evaluación en cola');

        Queue::assertPushed(RunForecastEvaluation::class);
    });

    it('el admin lanza la de un campus', function () {
        Queue::fake();

        Livewire::actingAs(User::factory()->admin()->create())->test('academic-load::pages.forecast-accuracy')
            ->call('openEvaluate')
            ->set('newModality', 'PRES')
            ->set('newTerm', $this->term->id)
            ->set('newCampus', 'K1')
            ->call('evaluate')
            ->assertHasNoErrors();

        expect(ForecastEvaluation::query()->sole()->campus_id)->toBe($this->c1->id);
    });

    it('DM-28: el error de RequestForecastEvaluation sale en su campo y no guarda nada', function () {
        Livewire::actingAs($this->subdireccion)->test('academic-load::pages.forecast-accuracy')
            ->call('openEvaluate')
            ->set('newModality', 'PRES')
            ->set('newTerm', $this->term->id)
            ->set('newCampus', 'K2')
            ->call('evaluate')
            ->assertHasErrors(['newTerm'])
            ->assertSee('No hay kárdex de Ene-Jun 2026 en Campus K2: no hay con qué comparar.')
            ->assertSet('showEvaluate', true);

        expect(ForecastEvaluation::query()->count())->toBe(0);
    });

    it('«Evaluar un periodo» abre aunque haya kárdex sin periodo', function () {
        KardexRecord::factory()->for(Student::query()->firstOrFail())->for($this->calculus)->create(['term_code' => null, 'result' => KardexResult::Aprobada]);

        Livewire::actingAs($this->subdireccion)->test('academic-load::pages.forecast-accuracy')
            ->call('openEvaluate')
            ->assertHasNoErrors()
            ->assertSet('showEvaluate', true)
            ->assertSee('Ene-Jun 2026');
    });

    it('solo ofrece los periodos con kárdex de la modalidad', function () {
        Term::factory()->create(['code' => '20253', 'name' => 'Ago-Dic 2025']);

        Livewire::actingAs($this->subdireccion)->test('academic-load::pages.forecast-accuracy')
            ->call('openEvaluate')
            ->assertSee('Ene-Jun 2026')
            ->assertDontSee('Ago-Dic 2025');
    });
});
