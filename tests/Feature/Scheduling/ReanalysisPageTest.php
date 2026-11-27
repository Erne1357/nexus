<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Queries\ReanalysisLoadsQuery;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Scheduling\ReanalysisWorld;

/*
| «Re-análisis de la carga» (tarea 8.2, pantalla, #602; opción B «Por materia», elegida por el usuario el 17 de noviembre
| de 2026): el jefe inicia el re-análisis de su carga publicada cuando llegan las calificaciones del semestre actual
| (RA-01, RA-02), ve la diferencia de demanda por materia y campus (RA-04, RA-09), lo cancela mientras no cambie nada
| (RA-10) o lo cierra en «Cierre de la carga». El coordinador ve solo su carrera y no ajusta (RA-07). Subdirección ve.
*/

beforeEach(function () {
    $this->world = ReanalysisWorld::create();
    $this->jefe = $this->world->head();
});

/**
 * @param  array<string, mixed>  $query
 */
function reanalysisPage(User $user, array $query = []): Testable
{
    return Livewire::withQueryParams($query)->actingAs($user)->test('scheduling::pages.reanalysis');
}

/**
 * Las carreras de los renglones completos a la vista, por materia y campus del bloque: «ACF-0902 C2» => ['ISIC', 'IIND'].
 *
 * @return array<string, list<string>>
 */
function reanalysisRowsOf(Testable $page): array
{
    $rows = [];

    foreach (domOf($page)->querySelectorAll('[data-testid="reanalysis-block"]') as $block) {
        $key = $block->getAttribute('data-subject').' '.$block->getAttribute('data-campus');
        $rows[$key] = array_map(fn ($row): string => $row->getAttribute('data-career'), iterator_to_array($block->querySelectorAll('[data-testid="reanalysis-row"], [data-testid="compact-career"]')));
    }

    return $rows;
}

/**
 * Las entradas del menú del usuario, en orden.
 *
 * @return list<string>
 */
function reanalysisMenuOf(User $user): array
{
    test()->actingAs($user);
    app()->forgetScopedInstances();

    return array_map(fn (NavigationItem $item): string => $item->label, app(Navigation::class)->visibleTo(app(Actor::class)));
}

/**
 * Quien ve completa la carga de Ciencias Básicas (`load.view` de su unidad) y, con `proposal.view`, las carreras en la de
 * otro departamento: Subdirección nombrada en la unidad de ese departamento (su puesto concede las dos cosas de fábrica;
 * el jefe de departamento no puede tener `proposal.view`, CF-04).
 */
function reanalysisMixedUser(Department $department): User
{
    return User::factory()->withRole(Role::Subdireccion, ['department_id' => $department->id])->create(['name' => 'Subdirección de CB']);
}

function reanalysisChip(Testable $page, string $key): string
{
    return textOf(domOf($page)->querySelector("[data-testid=\"chip-{$key}\"]"));
}

describe('jefe del departamento', function () {
    it('RA-02: con la carga publicada y las calificaciones del semestre actual ve el ciclo, el avance y puede iniciar', function () {
        $this->world->published()->gradesArrived();

        $page = reanalysisPage($this->jefe)
            ->assertSee('Re-análisis de la carga')
            ->assertSee('Ciencias Básicas')
            ->assertSee('Ago-Dic 2027')
            ->assertSee('Publicada · v1')
            ->assertSee('Ya puedes re-analizar la carga.')
            ->assertSee('Kárdex importado después de la publicación')
            ->assertSee('Calificaciones nuevas de Ene-Jun 2027')
            ->assertSee('1 de 2 capturadas (50 %)')
            ->assertSee('50 % capturadas')
            ->assertSeeHtml('data-confirm-title="¿Iniciar el re-análisis de Ciencias Básicas · Presencial?"');

        $start = domOf($page)->querySelector('[data-testid="start-reanalysis"]');
        expect($start->hasAttribute('disabled'))->toBeFalse()
            ->and(domOf($page)->querySelector('[data-step="reanalysis"]')->getAttribute('data-state'))->toBe('ready');
    });

    it('RA-02: sin la proyección recalculada «Iniciar re-análisis» está deshabilitado con su motivo, y una llamada directa no lo inicia', function () {
        $this->world->published()->gradesArrived(forecastRecomputed: false);

        $page = reanalysisPage($this->jefe)
            ->assertSee('Todavía no se puede re-analizar.')
            ->assertSee('La proyección de demanda todavía no se recalcula con el kárdex importado')
            ->assertSee('Falta la proyección');

        $start = domOf($page)->querySelector('[data-testid="start-reanalysis"]');
        expect($start->hasAttribute('disabled'))->toBeTrue()
            ->and($start->getAttribute('aria-describedby'))->toBe('reanalysis-line')
            ->and(domOf($page)->querySelector('#reanalysis-line'))->not->toBeNull()
            ->and(domOf($page)->querySelector('[data-check="forecast"]')->getAttribute('data-state'))->toBe('wait');

        $page->call('startReanalysis');

        expect($this->world->load->fresh()->status)->toBe(LoadStatus::Published)
            ->and(lastToastOf($page)['title'])->toBe('No se inició el re-análisis');
    });

    it('RA-02: sin kárdex nuevo, la condición lo dice una sola vez y las otras dos esperan (ensayo 8.11, E-06)', function () {
        $this->world->published();

        $page = reanalysisPage($this->jefe);
        $html = $page->html();

        expect(substr_count($html, 'No se ha importado un kárdex después de la versión 1 de la carga.'))->toBe(1)
            ->and(textOf(domOf($page)->querySelector('[data-check="kardex"]')))->toContain('No se ha importado un kárdex después de la versión 1 de la carga.')
            ->and(textOf(domOf($page)->querySelector('#reanalysis-line')))->toContain('Todavía no se puede re-analizar.')->toContain('«Lo que pide el re-análisis»')
            ->and(domOf($page)->querySelector('#why-start'))->toBeNull()
            ->and(domOf($page)->querySelector('[data-check="kardex"]')->getAttribute('data-state'))->toBe('wait')
            ->and(domOf($page)->querySelector('[data-check="grades"]')->getAttribute('data-state'))->toBe('pending');
    });

    it('RA-01: inicia el re-análisis desde la pantalla y ve los bloques por materia y campus', function () {
        $this->world->published()->gradesArrived();

        $page = reanalysisPage($this->jefe)->call('startReanalysis');

        expect($this->world->load->fresh()->status)->toBe(LoadStatus::Reanalysis)
            ->and(lastToastOf($page)['title'])->toBe('Re-análisis iniciado');

        $page->assertSee('En re-análisis')
            ->assertSee('Sergio Valdez')
            ->assertSee('Todavía no cambias nada.')
            ->assertSee('Cálculo Integral');

        expect(reanalysisRowsOf($page))->toBe([
            'ACF-0902 C2' => ['ISIC', 'IIND'],
            'ACF-0902 C1' => ['ISIC'],
            'ACF-0901 C2' => ['ISIC'],
        ]);
    });

    it('RA-04: cada carrera lleva su clase y la diferencia en porcentaje de lo esperado, no de los cupos', function () {
        $this->world->published()->gradesArrived()->inReanalysis($this->jefe);

        $page = reanalysisPage($this->jefe);
        $row = fn (string $block, string $career) => domOf($page)->querySelector("[data-subject=\"{$block}\"][data-campus=\"C2\"] [data-career=\"{$career}\"]");

        // ISIC: 58 esperados contra 40 lugares: +18, el 31 % de lo esperado (sobre los cupos sería 45 %).
        expect(textOf($row('ACF-0902', 'ISIC')))->toContain('58 esperados')->toContain('40 cupos en 1')->toContain('+18')->toContain('+31%')->toContain('Faltante')->not->toContain('+45')
            ->and(textOf($row('ACF-0902', 'IIND')))->toContain('+1')->toContain('+3%')->toContain('Ajustada')->toContain('Revisar:')
            ->and(textOf(domOf($page)->querySelector('[data-subject="ACF-0902"][data-campus="C1"] [data-career="ISIC"]')))->toContain('−15')->toContain('−50%')->toContain('Sobrante');

        expect(reanalysisChip($page, 'shortfall'))->toContain('Faltantes')->toContain('1')
            ->and(reanalysisChip($page, 'surplus'))->toContain('1')
            ->and(reanalysisChip($page, 'adjusted'))->toContain('1')
            ->and(reanalysisChip($page, 'new'))->toContain('1')
            ->and(reanalysisChip($page, 'review'))->toContain('1');

        // Primero la materia con faltantes, luego la de sobrantes y al final la de primer semestre.
        expect(array_keys(reanalysisRowsOf($page)))->toBe(['ACF-0902 C2', 'ACF-0902 C1', 'ACF-0901 C2']);
    });

    it('RA-09: la materia de primer semestre va compacta, con su nuevo ingreso y sin clasificar', function () {
        $this->world->published()->gradesArrived()->inReanalysis($this->jefe);

        $page = reanalysisPage($this->jefe);
        $block = domOf($page)->querySelector('[data-subject="ACF-0901"]');

        expect($block->hasAttribute('data-compact'))->toBeTrue()
            ->and(textOf($block))->toContain('Nuevo ingreso: 30')->toContain('Primer semestre: no se clasifica')->not->toContain('Faltante')->not->toContain('Ajustada');

        $page->call('toggleClass', 'new');

        expect(textOf(domOf($page)->querySelector('[data-subject="ACF-0901"] [data-career="ISIC"]')))->toContain('30 nuevo ingreso')->toContain('Sin clasificar')
            ->and(reanalysisRowsOf($page))->toBe(['ACF-0901 C2' => ['ISIC']]);
    });

    it('filtra por clase, por revisar, por búsqueda sin acentos y por campus, y quita los filtros', function () {
        $this->world->published()->gradesArrived()->inReanalysis($this->jefe);

        $page = reanalysisPage($this->jefe)->call('toggleClass', 'shortfall');
        expect(reanalysisRowsOf($page))->toBe(['ACF-0902 C2' => ['ISIC']]);

        $page->call('toggleClass', 'shortfall')->set('review', true);
        expect(reanalysisRowsOf($page))->toBe(['ACF-0902 C2' => ['IIND']]);

        $page->set('review', false)->set('search', 'calculo diferencial');
        expect(reanalysisRowsOf($page))->toBe(['ACF-0901 C2' => ['ISIC']]);

        $page->set('search', '')->set('campus', 'C1');
        expect(reanalysisRowsOf($page))->toBe(['ACF-0902 C1' => ['ISIC']])
            ->and(textOf(domOf($page)->querySelector('[data-testid="campus-count-C2"]')))->toBe('3');

        $page->call('clearFilters');
        expect(reanalysisRowsOf($page))->toHaveCount(3);
    });

    it('RA-10: sin cambios cancela el re-análisis y la carga vuelve a publicada sin versión nueva', function () {
        $this->world->published()->gradesArrived()->inReanalysis($this->jefe);

        $page = reanalysisPage($this->jefe)
            ->assertSeeHtml('data-confirm-title="¿Cancelar el re-análisis?"')
            ->call('cancelReanalysis');

        expect($this->world->load->fresh()->status)->toBe(LoadStatus::Published)
            ->and($this->world->load->fresh()->current_version)->toBe(1)
            ->and(LoadSnapshot::query()->where('academic_load_id', $this->world->load->id)->count())->toBe(1)
            ->and(lastToastOf($page)['title'])->toBe('Re-análisis cancelado');

        $page->assertSee('Publicada · v1');
    });

    it('RA-10: con un grupo cambiado, «Cancelar» está deshabilitado con su motivo, el bloque dice lo ajustado y la lista lo muestra', function () {
        $this->world->published()->gradesArrived()->inReanalysis($this->jefe);
        $this->world->groups['B']->update(['capacity' => 35]);
        GroupQuota::query()->where('group_id', $this->world->groups['B']->id)->update(['max_seats' => 35]);

        $page = reanalysisPage($this->jefe)
            ->assertSee('Ya cambiaste 1 grupo: ciérralo y publica la versión 2.');

        expect(textOf(domOf($page)->querySelector('[data-testid="reanalysis-line"]')))->toContain('Cambiaste 1 grupo contra la v1 · Ver')
            ->and(domOf($page)->querySelector('[data-testid="cancel-reanalysis"]')->hasAttribute('disabled'))->toBeTrue()
            ->and(textOf(domOf($page)->querySelector('[data-subject="ACF-0902"][data-campus="C2"] [data-testid="block-touched"]')))->toContain('Ya ajustaste B: cupo 30 → 35');

        $page->call('openChanges')
            ->assertSet('showChanges', true)
            ->assertSee('Lo que ya cambiaste')
            ->assertSee('ACF-0902 · B')
            ->assertSee('Cupo 30 → 35');

        $page->call('cancelReanalysis');

        expect($this->world->load->fresh()->status)->toBe(LoadStatus::Reanalysis)
            ->and(lastToastOf($page)['title'])->toBe('No se canceló el re-análisis');
    });

    it('«Cerrar re-análisis» lleva a «Cierre de la carga» del departamento en la modalidad', function () {
        $this->world->published()->gradesArrived()->inReanalysis($this->jefe);

        $page = reanalysisPage($this->jefe);
        $link = domOf($page)->querySelector('[data-testid="close-reanalysis"]');

        expect($link->getAttribute('href'))->toBe(route('staffing.closing', ['departamento' => $this->world->department->id, 'modalidad' => 'PRES']))
            ->and($link->getAttribute('data-confirm-button'))->toBe('Ir a Cierre de la carga');
    });

    it('cada bloque abre el drawer de sugerencias con la demanda por carrera y las sugerencias de la tarea 8.3a', function () {
        $this->world->published()->gradesArrived()->inReanalysis($this->jefe);

        reanalysisPage($this->jefe)->assertSeeHtml('data-testid="block-suggestions"');

        Livewire::actingAs($this->jefe)
            ->test('scheduling::components.reanalysis-suggestions', ['loadId' => $this->world->load->id])
            ->dispatch('open-reanalysis-suggestions', subjectId: $this->world->integral->id, campusId: $this->world->c2->id)
            ->assertSet('show', true)
            ->assertSee('Cálculo Integral')
            ->assertSee('Demanda por carrera')
            ->assertSeeHtml('data-testid="suggestions-evidence"')
            ->assertSee('Ampliar el cupo de ISIC en el grupo A de 40 a 50');
    });
});

describe('coordinador (RA-07)', function () {
    beforeEach(function () {
        $this->coordinator = $this->world->coordinator($this->world->isc);
    });

    it('RA-07: en re-análisis ve solo su carrera, sin acciones y con la nota de que ajusta la jefatura', function () {
        $this->world->published()->gradesArrived()->inReanalysis($this->jefe);

        $page = reanalysisPage($this->coordinator)
            ->assertSee('Solo ISIC')
            ->assertSee('Solo consulta: los ajustes los hace la jefatura de Ciencias Básicas.')
            ->assertDontSeeHtml('data-career="IIND"')
            ->assertDontSeeHtml('data-testid="cancel-reanalysis"')
            ->assertDontSeeHtml('data-testid="close-reanalysis"')
            ->assertDontSeeHtml('data-testid="block-suggestions"')
            ->assertDontSeeHtml('data-testid="open-changes"');

        expect(reanalysisRowsOf($page))->toBe([
            'ACF-0902 C2' => ['ISIC'],
            'ACF-0902 C1' => ['ISIC'],
            'ACF-0901 C2' => ['ISIC'],
        ]);
    });

    it('RA-07: con la carga publicada solo ve el aviso, sin «Iniciar re-análisis»', function () {
        $this->world->published()->gradesArrived();

        reanalysisPage($this->coordinator)
            ->assertSee('La carga de Ciencias Básicas está publicada')
            ->assertDontSeeHtml('data-testid="start-reanalysis"');
    });

    it('RA-07 (supuesto por confirmar): no ve el avance de calificaciones de toda la carga, ni publicada ni en re-análisis', function () {
        $this->world->published()->gradesArrived();

        reanalysisPage($this->coordinator)
            ->assertSee('Cuando la jefatura inicie el re-análisis')
            ->assertDontSee('capturadas')
            ->assertDontSee('calificaciones llegaron');

        $this->world->inReanalysis($this->jefe);

        reanalysisPage($this->coordinator)->assertDontSee('capturadas');
        reanalysisPage($this->jefe)->assertSee('capturadas');
    });

    it('RA-07: no inicia, no cancela ni abre lo que cambió aunque llame directo', function () {
        $this->world->published()->gradesArrived();
        reanalysisPage($this->coordinator)->call('startReanalysis')->assertForbidden();

        $this->world->inReanalysis($this->jefe);
        reanalysisPage($this->coordinator)->call('cancelReanalysis')->assertForbidden();
        reanalysisPage($this->coordinator)->call('openChanges')->assertForbidden();

        Livewire::actingAs($this->coordinator)
            ->test('scheduling::components.reanalysis-suggestions', ['loadId' => $this->world->load->id])
            ->dispatch('open-reanalysis-suggestions', subjectId: $this->world->integral->id, campusId: $this->world->c2->id)
            ->assertForbidden();

        expect($this->world->load->fresh()->status)->toBe(LoadStatus::Reanalysis);
    });

    it('RA-07: el coordinador de una carrera sin materias del departamento no ve ninguna carga', function () {
        $this->world->published()->gradesArrived()->inReanalysis($this->jefe);
        $other = $this->world->coordinator(Career::factory()->create(['code' => 'IGEM']));

        reanalysisPage($other)
            ->assertSee('Ninguna carga con materias de tu carrera está publicada')
            ->assertDontSeeHtml('data-testid="reanalysis-block"');
    });
});

describe('Subdirección', function () {
    it('RA-01: ve el re-análisis de cualquier departamento pero no lo inicia ni lo cancela', function () {
        $this->world->published()->gradesArrived();
        $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();

        reanalysisPage($subdireccion, ['departamento' => $this->world->department->id])
            ->assertSee('Lo inicia la jefatura de Ciencias Básicas.')
            ->assertDontSeeHtml('data-testid="start-reanalysis"')
            ->call('startReanalysis')
            ->assertForbidden();

        $this->world->inReanalysis($this->jefe);

        reanalysisPage($subdireccion, ['departamento' => $this->world->department->id])
            ->assertSee('Cálculo Integral')
            ->assertDontSeeHtml('data-testid="cancel-reanalysis"')
            ->call('cancelReanalysis')
            ->assertForbidden();

        expect($this->world->load->fresh()->status)->toBe(LoadStatus::Reanalysis);
    });
});

describe('alcance', function () {
    it('el docente no entra a la pantalla', function () {
        $this->world->published();

        $this->actingAs(User::factory()->withRole(Role::Docente)->create())->get(route('scheduling.reanalysis'))->assertForbidden();
    });

    it('quien no inicia sesión va al login', function () {
        $this->get(route('scheduling.reanalysis'))->assertRedirect(route('login'));
    });

    it('el jefe de otro departamento no abre esta carga ni la inicia sobre una página que abrió el jefe', function () {
        $this->world->published()->gradesArrived();
        $other = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => Department::factory()->create()->id])->create();

        reanalysisPage($other, ['departamento' => $this->world->department->id])->assertForbidden();

        $page = reanalysisPage($this->jefe);
        $this->actingAs($other);
        $page->call('startReanalysis')->assertForbidden();

        expect($this->world->load->fresh()->status)->toBe(LoadStatus::Published);
    });

    it('el jefe de otro departamento con su carga sin publicar lo lee en la pantalla', function () {
        $this->world->published();
        $department = Department::factory()->create(['name' => 'Metal-Mecánica']);
        AcademicLoad::factory()->create(['term_id' => $this->world->term->id, 'modality_id' => $this->world->modality->id, 'department_id' => $department->id]);
        $other = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $department->id])->create();

        reanalysisPage($other)->assertSee('No hay cargas publicadas en Presencial en Ago-Dic 2027');
        reanalysisPage($other, ['departamento' => $department->id])->assertSee('Metal-Mecánica no tiene una carga publicada en Presencial');
    });
});

describe('alcance por carga (RA-07, #620)', function () {
    beforeEach(function () {
        $this->world->published()->gradesArrived()->inReanalysis($this->jefe);
        $this->other = $this->world->otherDepartmentInReanalysis('MM', $this->world->isc);
        $this->mixed = reanalysisMixedUser($this->world->department);
    });

    it('quien ve completa la carga de su unidad y la propuesta de carreras ve la suya completa y, en la ajena, solo lo de sus carreras', function () {
        $page = reanalysisPage($this->mixed, ['departamento' => $this->world->department->id])
            ->assertDontSee('Solo consulta')
            ->assertSeeHtml('data-testid="block-suggestions"');

        expect(reanalysisRowsOf($page))->toBe([
            'ACF-0902 C2' => ['ISIC', 'IIND'],
            'ACF-0902 C1' => ['ISIC'],
            'ACF-0901 C2' => ['ISIC'],
        ])->and(collect($page->get('loads'))->pluck('id')->all())->toBe([$this->world->department->id, $this->other['department']->id]);

        $page = reanalysisPage($this->mixed, ['departamento' => $this->other['department']->id])
            ->assertOk()
            ->assertSee('Solo ISIC')
            ->assertSee('Solo consulta: los ajustes los hace la jefatura de Departamento MM.')
            ->assertSee('Materia de MM')
            ->assertDontSee('Cálculo Integral')
            ->assertDontSeeHtml('data-testid="cancel-reanalysis"')
            ->assertDontSeeHtml('data-testid="close-reanalysis"')
            ->assertDontSeeHtml('data-testid="block-suggestions"')
            ->assertDontSeeHtml('data-testid="open-changes"');

        expect(reanalysisRowsOf($page))->toBe(['MM-0101 C2' => ['ISIC']]);
    });

    it('en la carga ajena no abre lo que cambió ni las sugerencias aunque llame directo', function () {
        reanalysisPage($this->mixed, ['departamento' => $this->other['department']->id])->call('openChanges')->assertForbidden();

        Livewire::actingAs($this->mixed)
            ->test('scheduling::components.reanalysis-suggestions', ['loadId' => $this->other['load']->id])
            ->dispatch('open-reanalysis-suggestions', subjectId: $this->other['subject']->id, campusId: $this->world->c2->id)
            ->assertForbidden();

        expect($this->other['load']->fresh()->status)->toBe(LoadStatus::Reanalysis);
    });

    it('el select «Carga» del coordinador lista las cargas que imparten materias de su carrera, no las que no', function () {
        $coordinator = $this->world->coordinator($this->world->isc);

        $third = $this->world->otherDepartmentInReanalysis('QQ', $this->world->iind);

        reanalysisPage($coordinator)
            ->assertSet('loads', fn (array $loads): bool => collect($loads)->pluck('id')->all() === [$this->world->department->id, $this->other['department']->id])
            ->assertDontSee('Departamento QQ');

        reanalysisPage($coordinator, ['departamento' => $third['department']->id])->assertForbidden();
    });

    it('una materia quitada de la retícula, una materia borrada o inactiva y un plan borrado no dan alcance al coordinador', function () {
        $coordinator = $this->world->coordinator($this->world->isc);
        $loads = app(ReanalysisLoadsQuery::class);
        $isc = [$this->world->isc->id];
        $plan = StudyPlan::query()->where('career_id', $this->world->isc->id)->firstOrFail();
        $entry = StudyPlanSubject::query()->where('subject_id', $this->other['subject']->id)->where('study_plan_id', $plan->id)->firstOrFail();

        expect($loads->careersTaughtBy($this->other['department']->id, $isc))->toBe($isc);

        $entry->delete();

        expect($loads->careersTaughtBy($this->other['department']->id, $isc))->toBe([])
            ->and($loads->visibleLoads(app(Actor::class)->forUser($coordinator), $this->world->term->id, $this->world->modality->id)->pluck('department_id')->all())->toBe([$this->world->department->id]);

        reanalysisPage($coordinator, ['departamento' => $this->other['department']->id])->assertForbidden();

        $entry->restore();
        $this->other['subject']->update(['is_active' => false]);

        expect($loads->careersTaughtBy($this->other['department']->id, $isc))->toBe([]);

        $this->other['subject']->update(['is_active' => true]);
        $plan->delete();

        expect($loads->careersTaughtBy($this->other['department']->id, $isc))->toBe([])
            ->and($loads->careersTaughtBy($this->world->department->id, $isc))->toBe([]);
    });

    it('el coordinador de una carrera que no se imparte en la carga recibe 403, al abrirla y en cada método', function () {
        $igem = Career::factory()->create(['code' => 'IGEM']);
        $coordinator = $this->world->coordinator($this->world->isc);
        $outsider = $this->world->coordinator($igem);

        reanalysisPage($outsider, ['departamento' => $this->other['department']->id])->assertForbidden();

        // La página que abrió el coordinador de ISIC no sirve con otro usuario a la mitad.
        $page = reanalysisPage($coordinator, ['departamento' => $this->other['department']->id])->assertSee('Materia de MM');
        $this->actingAs($outsider);
        $page->call('openChanges')->assertForbidden();

        $page = reanalysisPage($coordinator, ['departamento' => $this->other['department']->id]);
        $this->actingAs($outsider);
        $page->call('cancelReanalysis')->assertForbidden();
    });

    it('el jefe de un departamento, que no ve la propuesta de carreras, sigue sin ver la carga ajena', function () {
        $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => Department::factory()->create()->id])->create();

        reanalysisPage($jefe, ['departamento' => $this->other['department']->id])->assertForbidden();
    });
});

describe('consultas', function () {
    it('#620: el tablero lee un número fijo de consultas, sin importar cuántas materias, campus y carreras tiene el diff', function () {
        $this->world->published()->gradesArrived()->inReanalysis($this->jefe);
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            reanalysisPage($this->jefe)->call('toggleClass', 'shortfall')->set('search', 'acf');
            DB::disableQueryLog();
            $this->payloadReads = collect(DB::getQueryLog())->filter(fn (array $log): bool => str_contains($log['query'], 'from "load_snapshots"') && str_contains($log['query'], '"payload"'))->count();

            return count(DB::getQueryLog());
        };

        $queries(); // la primera vuelta llena las cachés de permisos y catálogos
        $few = $queries();
        $rowsBefore = count(domOf(reanalysisPage($this->jefe))->querySelectorAll('[data-testid="reanalysis-block"]'));
        // Después de publicar: más renglones de demanda y grupos nuevos que aparecen también en «Lo que ya cambiaste».
        $this->world->addSubjects(6);
        $many = $queries();
        $rowsAfter = count(domOf(reanalysisPage($this->jefe))->querySelectorAll('[data-testid="reanalysis-block"]'));

        expect($rowsAfter)->toBeGreaterThan($rowsBefore)
            ->and($this->payloadReads)->toBe(3) // una lectura del payload por petición (montar y dos acciones), no dos
            ->and($many)->toBe($few)
            ->and($many)->toBeLessThanOrEqual(110);
    });
});

describe('menú', function () {
    // Desde la tarea 1.56 el jefe con su carga publicada ve las pantallas de publicación y re-análisis, no el cierre (MN-06): el
    // orden después de «Cierre de la carga» se revisa en el menú del admin, que lo ve todo.
    it('MN-02: «Re-análisis de la carga» va después de «Cierre de la carga» cuando hay una carga publicada; el jefe y el coordinador la ven; el docente no', function () {
        $this->world->published();
        // Otra carga sin publicar en el periodo: así las pantallas de la carga también están disponibles (MN-04).
        AcademicLoad::factory()->create(['term_id' => $this->world->term->id, 'modality_id' => $this->world->modality->id, 'department_id' => Department::factory()->create()->id]);
        $admin = reanalysisMenuOf(User::factory()->admin()->create());

        expect(array_slice($admin, array_search('Cierre de la carga', $admin, true), 4))->toBe(['Cierre de la carga', 'Avance del cierre', 'Re-análisis de la carga', 'Versiones publicadas'])
            ->and(reanalysisMenuOf($this->jefe))->toContain('Re-análisis de la carga', 'Versiones publicadas')->not->toContain('Cierre de la carga')
            ->and(reanalysisMenuOf($this->world->coordinator($this->world->isc)))->toContain('Re-análisis de la carga')
            ->and(reanalysisMenuOf(User::factory()->withRole(Role::Docente)->create()))->not->toContain('Re-análisis de la carga');
    });

    it('MN-02: sin ninguna carga publicada en el periodo, no está disponible', function () {
        expect(reanalysisMenuOf($this->jefe))->not->toContain('Re-análisis de la carga');
    });
});
