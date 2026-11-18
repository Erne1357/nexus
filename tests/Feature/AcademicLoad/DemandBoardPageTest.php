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

    it('la matriz solo lleva las carreras con alguna celda en lo que se ve (decidido el 17 de noviembre de 2026)', function () {
        $codes = fn (array $columns): array => collect($columns)->map(fn (array $column): string => $column['career']->code)->sort()->values()->all();

        demandPageAs($this->jefe)->assertViewHas('columns', fn (array $columns): bool => $codes($columns) === ['IGE', 'ISIC']);
        // En Campus II solo IGE pide o tiene sugeridos: ISIC no ocupa columna.
        demandPageAs($this->jefe, ['campus' => 'C2'])->assertViewHas('columns', fn (array $columns): bool => $codes($columns) === ['IGE']);
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
            // Debajo de 768 px, los mismos renglones apilados (DESIGN.md, «Responsivo»).
            ->assertSeeHtml('data-testid="demand-by-campus-list"')
            ->assertSeeHtml('data-testid="demand-drawer-item-ISIC-C1"')
            ->assertSee('Historia de la materia en Ene-Jun')
            ->assertSee('Historia de la materia')
            ->assertSee('Requiere laboratorio')
            ->assertSee('Sin propuesta enviada: cuenta lo sugerido.')
            ->assertSeeHtml('data-testid="demand-open-inbox"')
            ->set('showDrawer', false)
            ->assertSet('subjectCode', '');
    });

    it('la materia del drawer viaja en la URL; una que no es del tablero no lo abre por la URL y es 404 por el método', function () {
        demandPageAs($this->jefe, ['materia' => 'SCD-1027'])->assertSet('showDrawer', true)->assertSeeHtml('data-testid="demand-panel"');
        demandPageAs($this->jefe, ['materia' => 'ACF-0901'])->assertSet('showDrawer', false)->assertSet('subjectCode', '')->assertDontSeeHtml('data-testid="demand-panel"');
        demandPageAs($this->jefe)->call('open', 'ACF-0901')->assertNotFound();
    });

    it('pintar no cambia el estado: si la materia sale del tablero con el drawer abierto, el drawer lo dice', function () {
        $page = demandPageAs($this->jefe)->call('open', 'SCD-1027');
        DemandForecast::query()->where('subject_id', $this->topics->id)->delete();
        ProposalItem::query()->where('subject_id', $this->topics->id)->delete();

        $page->call('$refresh')
            ->assertSet('showDrawer', true)
            ->assertSet('subjectCode', 'SCD-1027')
            ->assertSeeHtml('data-testid="demand-panel-gone"');
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

    it('quien tiene las dos lentes (el admin) elige una carrera y vuelve a la del departamento con «Ver por departamento»', function () {
        demandPageAs(User::factory()->admin()->create())
            ->assertSet('departmentCode', 'CB')
            ->assertSet('careerCode', '')
            ->assertSeeHtml('data-testid="demand-career"')
            ->assertSee('Ver por departamento')
            ->set('careerCode', 'ISIC')
            ->assertSet('careerCode', 'ISIC')
            ->assertSeeHtml('data-testid="demand-read-only"')
            ->assertSee('SCD-1027')
            ->set('careerCode', '')
            ->assertSet('careerCode', '')
            ->assertSet('departmentCode', 'CB')
            ->assertDontSeeHtml('data-testid="demand-read-only"')
            ->assertDontSee('SCD-1027')
            ->assertSee('ACF-0901');
    });

    it('con dos puestos, cada perfil ve su lente y el de jefe no ofrece la de carrera', function () {
        $both = User::factory()
            ->withRole(Role::JefeDepartamento, ['department_id' => $this->cb->id])
            ->withRole(Role::Coordinador, ['campus_id' => $this->c1->id, 'career_id' => $this->isic->id])
            ->create();

        actingAsRole($both, Role::JefeDepartamento);
        Livewire::test('academic-load::pages.demand')->assertSet('departmentCode', 'CB')->assertDontSeeHtml('data-testid="demand-career"');

        actingAsRole($both, Role::Coordinador);
        Livewire::test('academic-load::pages.demand')->assertSet('careerCode', 'ISIC')->assertSeeHtml('data-testid="demand-read-only"');
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

    it('filtra por el departamento de la materia, y las cifras dicen de qué departamento son', function () {
        demandPageAs($this->coordinator, ['departamento' => 'CB'])
            ->assertSee('ACF-0901')
            ->assertDontSee('SCD-1027')
            ->assertViewHas('figures', fn (array $figures): bool => $figures['subjects'] === 1)
            ->assertSeeHtml('data-testid="figures-scope"')
            ->assertSee('el departamento del filtro.')
            ->assertSee('2 materias de 2 departamentos');

        demandPageAs($this->coordinator)->assertDontSeeHtml('data-testid="figures-scope"');
    });

    it('un departamento que no tiene materias en su lente no filtra y se suelta', function () {
        demandPageAs($this->coordinator, ['departamento' => 'MM'])
            ->assertSet('departmentCode', '')
            ->assertSee('ACF-0901')
            ->assertSee('SCD-1027');
    });

    it('filtrar por departamento no cierra el drawer de la materia', function () {
        demandPageAs($this->coordinator, ['materia' => 'SCD-1027'])
            ->set('departmentCode', 'CB')
            ->assertSet('showDrawer', true)
            ->assertSet('subjectCode', 'SCD-1027');
    });

    it('el drawer lleva a la propuesta de su carrera y no a la bandeja', function () {
        demandPageAs($this->coordinator)
            ->call('open', 'ACF-0901')
            ->assertSee('(tu carrera)')
            ->assertSeeHtml('data-testid="demand-open-proposal"')
            ->assertDontSeeHtml('data-testid="demand-open-inbox"');
    });

    it('sin propuesta de su carrera, el drawer dice su estado sin enlace a la propuesta, y nada arma un borrador', function () {
        $coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->ige->id])->create();
        $proposals = CareerProposal::query()->count();

        demandPageAs($coordinator)
            ->assertSet('careerCode', 'IGE')
            ->call('open', 'ACF-0901')
            ->assertDontSeeHtml('data-testid="demand-open-proposal"')
            ->assertSeeHtml('data-testid="demand-proposal-state"')
            ->assertSee('Sin propuesta');

        expect(CareerProposal::query()->count())->toBe($proposals)
            ->and(CareerProposal::query()->where('career_id', $this->ige->id)->exists())->toBeFalse();
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
