<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\HistoricalGroup;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Demand\Models\DemandForecast;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| «Demanda por materia» (tarea 5.7): el jefe y el jefe de proyecto docente ven las materias que imparte su departamento;
| Subdirección y el admin, las de cualquier departamento con el selector; el coordinador, las de su carrera en todos los
| departamentos (lente de carrera, solo consulta). Nadie escribe. Confirmado por el usuario el 16 de noviembre de 2026.
*/

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    TermOpening::factory()->create(['term_id' => $this->term->id, 'modality_id' => modalityOf('PRES')->id]);
    $this->sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->cb = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $this->isic = demandPageCareer('ISIC', 'Ingeniería en Sistemas Computacionales');
    $this->ige = demandPageCareer('IGE', 'Ingeniería en Gestión Empresarial');
    $this->topics = Subject::factory()->create(['code' => 'SCD-1027', 'name' => 'Tópicos Avanzados de Programación', 'department_id' => $this->sc->id, 'needs_lab' => true]);
    $this->calculus = Subject::factory()->create(['code' => 'ACF-0901', 'name' => 'Cálculo Diferencial', 'department_id' => $this->cb->id]);

    demandPageForecast($this->isic, $this->topics, $this->c1, 2);
    demandPageForecast($this->ige, $this->topics, $this->c2, 1);
    demandPageForecast($this->isic, $this->calculus, $this->c1, 4);
    demandPageForecast($this->ige, $this->calculus, $this->c2, 3);
    $proposal = CareerProposal::factory()->inStatus(ProposalStatus::Submitted)->create(['term_id' => $this->term->id, 'career_id' => $this->isic->id]);
    ProposalItem::factory()->create(['career_proposal_id' => $proposal->id, 'subject_id' => $this->topics->id, 'campus_id' => $this->c1->id, 'requested_groups' => 4]);
    ProposalItem::factory()->create(['career_proposal_id' => $proposal->id, 'subject_id' => $this->calculus->id, 'campus_id' => $this->c1->id, 'requested_groups' => 4]);

    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sc->id])->create();
    $this->coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c1->id, 'career_id' => $this->isic->id])->create();
});

function demandPageCareer(string $code, string $name): Career
{
    $career = Career::factory()->create(['code' => $code, 'name' => $name]);
    CareerCampus::factory()->create(['career_id' => $career->id, 'campus_id' => test()->c1->id]);
    CareerCampus::factory()->create(['career_id' => $career->id, 'campus_id' => test()->c2->id]);

    return $career;
}

function demandPageForecast(Career $career, Subject $subject, Campus $campus, int $suggested): DemandForecast
{
    return DemandForecast::factory()->create([
        'term_id' => test()->term->id,
        'modality_id' => modalityOf('PRES')->id,
        'career_id' => $career->id,
        'subject_id' => $subject->id,
        'campus_id' => $campus->id,
        'suggested_groups' => $suggested,
        'computed_at' => '2026-11-17 06:10:00',
    ]);
}

/**
 * @param  array<string, string>  $query
 */
function demandPageAs(User $user, array $query = []): Testable
{
    return Livewire::withQueryParams($query)->actingAs($user)->test('academic-load::pages.demand');
}

describe('jefe de departamento', function () {
    it('ve las materias que imparte su departamento, de todas las carreras, con las cuatro cifras', function () {
        demandPageAs($this->jefe)
            ->assertOk()
            ->assertSet('departmentCode', 'SC')
            ->assertSee('Sistemas y Computación')
            ->assertSee('SCD-1027')
            ->assertDontSee('ACF-0901')
            ->assertSee('1 materia en 2 carreras')
            ->assertSee('Proyección del 17/11/2026 a las 6:10')
            ->assertSee('Propuestas enviadas:')
            ->assertSee('Resalta lo que se aparta 20 % o más')
            ->assertSeeHtml('data-testid="demand-column-ISIC"')
            ->assertSeeHtml('data-testid="demand-column-IGE"')
            ->assertViewHas('figures', fn (array $figures): bool => $figures['suggested'] === 3 && $figures['counted'] === 5 && $figures['pendingSuggested'] === 1)
            ->assertDontSeeHtml('data-testid="demand-read-only"')
            ->assertDontSeeHtml('data-testid="demand-department"');
    });

    it('resalta la celda que se aparta, con su icono, y puntea la de la carrera sin propuesta', function () {
        $page = domOf(demandPageAs($this->jefe));
        $large = $page->querySelector('[data-testid="demand-cell-SCD-1027-ISIC"]');
        $pending = $page->querySelector('[data-testid="demand-cell-SCD-1027-IGE"]');

        expect($large->hasAttribute('data-large'))->toBeTrue()
            ->and($large->querySelector('svg'))->not->toBeNull()
            ->and(textOf($large))->toContain('ISIC: 4 grupos pedidos de 2 sugeridos')
            ->and($pending->hasAttribute('data-pending'))->toBeTrue()
            ->and(textOf($pending))->toContain('IGE: sin propuesta; cuenta 1 grupo sugerido');
    });

    it('no ve otro departamento ni lo pide por la URL', function () {
        demandPageAs($this->jefe, ['departamento' => 'CB'])->assertForbidden();
    });

    it('pedir una carrera no le cambia la lente: sigue en la de su departamento', function () {
        demandPageAs($this->jefe, ['carrera' => 'ISIC'])->assertSet('careerCode', '')->assertSet('departmentCode', 'SC')->assertDontSee('ACF-0901');
    });

    it('los filtros viajan en la URL: búsqueda, con diferencia, sin propuesta y campus', function () {
        demandPageAs($this->jefe, ['filtro' => 'diferencia'])
            ->assertSet('filter', 'diferencia')
            ->assertViewHas('visible', fn (array $rows): bool => count($rows) === 1)
            ->assertSeeHtml('aria-pressed="true" data-testid="demand-filter-diferencia"');

        demandPageAs($this->jefe, ['buscar' => 'calculo'])->assertViewHas('visible', fn (array $rows): bool => $rows === [])->assertSee('Ninguna materia con esos filtros.');
        demandPageAs($this->jefe, ['buscar' => 'topicos'])->assertViewHas('visible', fn (array $rows): bool => count($rows) === 1);
        demandPageAs($this->jefe, ['filtro' => 'cualquier-cosa'])->assertSet('filter', '');
        $onC2 = demandPageAs($this->jefe, ['campus' => 'C2'])
            ->assertViewHas('campusId', $this->c2->id)
            ->assertViewHas('filterCounts', ['all' => 1, 'difference' => 0, 'pending' => 1]);

        expect(domOf($onC2)->querySelector('[data-testid="campus-filter-C2"]')->getAttribute('aria-pressed'))->toBe('true');
    });

    it('quitar filtros deja la búsqueda y el filtro vacíos', function () {
        demandPageAs($this->jefe, ['buscar' => 'nada', 'filtro' => 'diferencia'])
            ->assertSee('Quitar filtros')
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('filter', '')
            ->assertViewHas('visible', fn (array $rows): bool => count($rows) === 1);
    });

    it('abre el drawer de una materia con el desglose por campus y por carrera y su historial', function () {
        HistoricalGroup::factory()->fromSii()->create(['subject_id' => $this->topics->id, 'term_code' => '20261', 'campus_id' => $this->c1->id, 'group_code' => 'A', 'capacity' => 35, 'enrolled' => 31]);

        demandPageAs($this->jefe)
            ->call('open', 'SCD-1027')
            ->assertSet('showDrawer', true)
            ->assertSet('subjectCode', 'SCD-1027')
            ->assertSeeHtml('data-testid="demand-by-campus"')
            ->assertSeeHtml('data-testid="demand-drawer-row-ISIC-C1"')
            ->assertSeeHtml('data-testid="demand-drawer-row-IGE-C2"')
            ->assertSee('Historia de la materia en Ene-Jun')
            ->assertSee('Historia de la materia')
            ->assertSee('Requiere laboratorio')
            ->assertSee('Sin propuesta enviada: cuenta lo sugerido.')
            ->assertSeeHtml('data-testid="demand-open-inbox"')
            ->set('showDrawer', false)
            ->assertSet('subjectCode', '');
    });

    it('la materia del drawer viaja en la URL, y una que no es del tablero no lo abre', function () {
        demandPageAs($this->jefe, ['materia' => 'SCD-1027'])->assertSet('showDrawer', true)->assertSeeHtml('data-testid="demand-panel"');
        demandPageAs($this->jefe)->call('open', 'ACF-0901')->assertSet('showDrawer', false)->assertDontSeeHtml('data-testid="demand-panel"');
    });

    it('un jefe de otro departamento no abre el drawer de este llamando el método directo', function () {
        $other = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->cb->id])->create();

        $page = demandPageAs($this->jefe);
        $this->actingAs($other);
        $page->call('open', 'SCD-1027')->assertForbidden();
    });
});

describe('jefe de proyecto docente', function () {
    it('ve las materias de su departamento y no las de otro', function () {
        $proyecto = User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->c2->id, 'department_id' => $this->sc->id])->create();

        demandPageAs($proyecto)->assertOk()->assertSee('SCD-1027')->assertDontSee('ACF-0901');
        demandPageAs($proyecto, ['departamento' => 'CB'])->assertForbidden();
    });
});

describe('Subdirección y admin', function () {
    it('Subdirección ve cualquier departamento con el selector «Departamento»', function () {
        $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();

        demandPageAs($subdireccion)
            ->assertSeeHtml('data-testid="demand-department"')
            ->assertSet('departmentCode', 'CB')
            ->assertSee('ACF-0901')
            ->set('departmentCode', 'SC')
            ->assertSee('SCD-1027')
            ->assertDontSee('ACF-0901');

        demandPageAs($subdireccion, ['departamento' => 'SC'])->assertSee('SCD-1027');
    });

    it('el admin ve la lente del departamento y, si pide una carrera, la de la carrera', function () {
        $admin = User::factory()->admin()->create();

        demandPageAs($admin, ['departamento' => 'SC'])->assertSee('SCD-1027')->assertDontSee('ACF-0901');
        demandPageAs($admin, ['carrera' => 'ISIC'])->assertSee('SCD-1027')->assertSee('ACF-0901')->assertSeeHtml('data-testid="demand-read-only"');
    });
});

describe('coordinador', function () {
    it('ve la lente de su carrera: sus materias de todos los departamentos con las demás carreras, solo consulta', function () {
        demandPageAs($this->coordinator)
            ->assertOk()
            ->assertSet('careerCode', 'ISIC')
            ->assertSeeHtml('data-testid="demand-read-only"')
            ->assertSee('Ingeniería en Sistemas Computacionales')
            ->assertSee('2 materias de 2 departamentos')
            ->assertSee('ACF-0901')
            ->assertSee('SCD-1027')
            ->assertSeeHtml('data-testid="demand-column-IGE"')
            ->assertSeeHtml('data-testid="demand-filter-department"')
            ->assertDontSeeHtml('data-testid="demand-career"');
    });

    it('filtra por el departamento de la materia', function () {
        demandPageAs($this->coordinator, ['departamento' => 'CB'])
            ->assertSee('ACF-0901')
            ->assertDontSee('SCD-1027')
            ->assertViewHas('figures', fn (array $figures): bool => $figures['subjects'] === 1);
    });

    it('el drawer lleva a la propuesta de su carrera y no a la bandeja', function () {
        demandPageAs($this->coordinator)
            ->call('open', 'ACF-0901')
            ->assertSee('(tu carrera)')
            ->assertSeeHtml('data-testid="demand-open-proposal"')
            ->assertDontSeeHtml('data-testid="demand-open-inbox"');
    });

    it('no ve otra carrera ni la pide por la URL', function () {
        $other = demandPageCareer('ITIC', 'Ingeniería en Tecnologías de la Información');

        demandPageAs($this->coordinator, ['carrera' => $other->code])->assertForbidden();
    });

    it('no abre la lente de otra carrera llamando el método directo en una página que abrió otro coordinador', function () {
        $other = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->ige->id])->create();

        $page = demandPageAs($this->coordinator);
        $this->actingAs($other);
        $page->call('open', 'ACF-0901')->assertForbidden();
    });
});

describe('sin permiso', function () {
    it('un docente recibe 403, y también quien no tiene puesto', function () {
        demandPageAs(User::factory()->withRole(Role::Docente)->create())->assertForbidden();
        demandPageAs(User::factory()->create())->assertForbidden();
    });

    it('un docente no abre el drawer en una página que abrió el admin', function () {
        $page = demandPageAs(User::factory()->admin()->create(), ['departamento' => 'SC']);
        $this->actingAs(User::factory()->withRole(Role::Docente)->create());

        $page->call('open', 'SCD-1027')->assertForbidden();
    });
});

describe('sin tablero', function () {
    it('sin periodo activo lo dice', function () {
        $this->term->update(['is_current' => false]);

        demandPageAs($this->jefe)->assertSee('No hay un periodo activo: elige uno en tu credencial.');
    });

    it('con el periodo sin abrir en la modalidad lo dice', function () {
        TermOpening::query()->delete();

        demandPageAs($this->jefe)->assertSee('El periodo Ene-Jun 2027 no está abierto en Presencial.');
    });

    it('sin proyección del departamento lo dice', function () {
        DemandForecast::query()->delete();
        ProposalItem::query()->delete();

        demandPageAs($this->jefe)->assertSee('Todavía no hay demanda que mostrar.')->assertSee('Todavía no hay proyección');
    });
});

describe('ruta y menú', function () {
    it('sirve la ruta al jefe y manda al invitado al login', function () {
        $this->get(route('academic-load.demand'))->assertRedirect(route('login'));
        $this->actingAs($this->jefe)->get(route('academic-load.demand'))->assertOk()->assertSee('Demanda por materia');
    });

    it('la entrada del menú la ven el jefe, el de proyecto docente, Subdirección, el coordinador y el admin, y no un docente', function () {
        AcademicLoad::factory()->create(['term_id' => $this->term->id, 'modality_id' => modalityOf('PRES')->id, 'department_id' => $this->sc->id]);
        $labels = function (User $user): array {
            $this->actingAs($user);

            return array_map(fn ($item) => $item->label, app(Navigation::class)->visibleTo(app(Actor::class)->forUser($user)));
        };
        $proyecto = User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->c1->id, 'department_id' => $this->sc->id])->create();

        expect($labels($this->jefe))->toContain('Demanda por materia')
            ->and($labels($proyecto))->toContain('Demanda por materia')
            ->and($labels(User::factory()->withRole(Role::Subdireccion)->create()))->toContain('Demanda por materia')
            ->and($labels($this->coordinator))->toContain('Demanda por materia')
            ->and($labels(User::factory()->admin()->create()))->toContain('Demanda por materia')
            ->and($labels(User::factory()->withRole(Role::Docente)->create()))->not->toContain('Demanda por materia');
    });
});
