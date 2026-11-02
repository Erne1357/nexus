<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Queries\ScopedContext;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| «Propuesta de carrera» (tarea 2.7): el coordinador revisa y corrige lo que su carrera pide a los
| departamentos en la retícula o en la tabla, resalta la parte de un departamento y envía la propuesta.
| Subdirección la ve sin editar (reglas-de-negocio.md, "Propuesta de carrera").
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    $this->term = Term::factory()->current()->create(['code' => '20271']);
    TermOpening::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id]);
    $this->career = Career::factory()->create(['code' => 'ISC', 'name' => 'Sistemas']);
    CareerCampus::factory()->create(['career_id' => $this->career->id, 'campus_id' => $this->campus->id]);
    $this->cb = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $this->sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->proposal = CareerProposal::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'career_id' => $this->career->id]);
    $this->calculus = proposalPageRow($this->proposal, $this->cb, 'ACF-0901', 'Cálculo Diferencial', 1, 2, 70);
    $this->networks = proposalPageRow($this->proposal, $this->sc, 'SCD-1015', 'Redes de Computadoras', 5, 1, 30);

    $this->coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->career->id])->create();
    $this->subdirection = User::factory()->withRole(Role::Subdireccion)->create();
});

function proposalPageRow(CareerProposal $proposal, Department $department, string $code, string $name, int $semester, int $groups, int $expected): ProposalItem
{
    return ProposalItem::factory()->create([
        'career_proposal_id' => $proposal->id,
        'subject_id' => Subject::factory()->create(['code' => $code, 'name' => $name, 'department_id' => $department->id])->id,
        'semester' => $semester,
        'eligible_students' => $expected,
        'expected_students' => $expected,
        'requested_groups' => $groups,
    ]);
}

/**
 * @param  array<string, string>  $query
 */
function proposalPageAs(User $user, array $query = []): Testable
{
    return Livewire::withQueryParams($query)->actingAs($user)->test('academic-load::pages.proposal');
}

describe('coordinador', function () {
    it('ve la propuesta de su carrera y campus con su estado y sus totales', function () {
        proposalPageAs($this->coordinator)
            ->assertOk()
            ->assertSee('ISC')
            ->assertSee('Borrador')
            ->assertSee('Cálculo Diferencial')
            ->assertSee('Redes de Computadoras')
            ->assertViewHas('overview', fn ($overview): bool => $overview->totals === ['subjects' => 2, 'groups' => 3, 'expected' => 100]);
    });

    it('muestra la retícula por omisión y la tabla con ?vista=tabla; otro valor muestra la retícula', function () {
        proposalPageAs($this->coordinator)->assertSet('view', 'reticula')->assertSeeHtml('data-testid="proposal-grid"');
        proposalPageAs($this->coordinator, ['vista' => 'tabla'])->assertSet('view', 'tabla')->assertSeeHtml('data-testid="proposal-table"')->assertDontSeeHtml('data-testid="proposal-grid"');
        proposalPageAs($this->coordinator, ['vista' => 'otra'])->assertSet('view', 'reticula');
    });

    it('cambia de vista y la deja en la URL', function () {
        proposalPageAs($this->coordinator)->call('setView', 'tabla')->assertSet('view', 'tabla')->assertSeeHtml('data-testid="proposal-table"');
    });

    it('resalta las materias de un departamento elegido, que va en la URL, y lo quita al elegirlo otra vez', function () {
        proposalPageAs($this->coordinator, ['depto' => 'CB'])
            ->assertSet('department', 'CB')
            ->assertSeeHtml('data-testid="proposal-grid"')
            ->assertSeeHtml('data-focus')
            ->call('pickDepartment', 'CB')
            ->assertSet('department', '')
            ->assertDontSeeHtml('data-focus')
            ->call('pickDepartment', 'SC')
            ->assertSet('department', 'SC');
    });

    it('ignora un departamento que la propuesta no involucra', function () {
        proposalPageAs($this->coordinator, ['depto' => 'XX'])->assertSet('department', '')->assertDontSeeHtml('data-focus');
    });

    it('en la tabla filtra a las filas del departamento elegido', function () {
        proposalPageAs($this->coordinator, ['vista' => 'tabla', 'depto' => 'CB', 'filtrar' => '1'])
            ->assertSee('Cálculo Diferencial')
            ->assertDontSee('Redes de Computadoras');
    });

    it('resume cada departamento con su estado, su comentario y sus grupos', function () {
        ProposalReview::factory()->returned('Falta un grupo.')->create(['career_proposal_id' => $this->proposal->id, 'department_id' => $this->sc->id]);
        ProposalReview::factory()->accepted()->create(['career_proposal_id' => $this->proposal->id, 'department_id' => $this->cb->id]);

        proposalPageAs($this->coordinator)
            ->assertSee('Ciencias Básicas')
            ->assertSee('Aceptada')
            ->assertSee('Regresada')
            ->assertSee('Falta un grupo.');
    });

    it('abre una materia en el panel con sus valores y guarda los cambios por la Action', function () {
        proposalPageAs($this->coordinator)
            ->call('edit', $this->calculus->id)
            ->assertSet('showDrawer', true)
            ->assertSet('expected_students', 70)
            ->assertSet('requested_groups', 2)
            ->set('requested_groups', 3)
            ->set('notes', 'Dos grupos de más.')
            ->call('save')
            ->assertSet('showDrawer', false);

        expect($this->calculus->fresh())->requested_groups->toBe(3)->notes->toBe('Dos grupos de más.')
            ->and(Activity::query()->where('subject_id', $this->calculus->id)->exists())->toBeTrue();
    });

    it('«Usar sugerido» llena los grupos con los que sugiere la regla de los 35 alumnos', function () {
        proposalPageAs($this->coordinator)
            ->call('edit', $this->calculus->id)
            ->set('expected_students', 71)
            ->call('useSuggested')
            ->assertSet('requested_groups', 3);
    });

    it('muestra el error en español de un valor fuera de rango y no guarda', function () {
        proposalPageAs($this->coordinator)
            ->call('edit', $this->calculus->id)
            ->set('requested_groups', 99)
            ->call('save')
            ->assertHasErrors('requested_groups')
            ->assertSee('Los grupos solicitados van de 0 a 30.');

        expect($this->calculus->fresh()->requested_groups)->toBe(2);
    });

    it('guarda una celda de la tabla al salir del campo', function () {
        proposalPageAs($this->coordinator, ['vista' => 'tabla'])
            ->call('saveCell', $this->calculus->id, 'requested_groups', '4');

        expect($this->calculus->fresh())->requested_groups->toBe(4)->expected_students->toBe(70);

        proposalPageAs($this->coordinator, ['vista' => 'tabla'])->call('saveCell', $this->calculus->id, 'expected_students', '35');

        expect($this->calculus->fresh())->requested_groups->toBe(4)->expected_students->toBe(35);
    });

    it('no edita una fila de un departamento que ya aceptó su parte y dice por qué', function () {
        ProposalReview::factory()->accepted()->create(['career_proposal_id' => $this->proposal->id, 'department_id' => $this->cb->id]);

        $page = proposalPageAs($this->coordinator, ['vista' => 'tabla'])
            ->assertSee('CB ya aceptó su parte')
            ->call('saveCell', $this->calculus->id, 'requested_groups', '4');

        expect(lastToastOf($page)['type'])->toBe('error')
            ->and($this->calculus->fresh()->requested_groups)->toBe(2);
    });

    it('con la propuesta regresada, la parte que un departamento acepta mientras tanto se ve aceptada y bloquea sus materias', function () {
        $this->proposal->update(['status' => ProposalStatus::Returned]);
        ProposalReview::factory()->returned('Falta un grupo.')->create(['career_proposal_id' => $this->proposal->id, 'department_id' => $this->sc->id]);
        $cbPart = ProposalReview::factory()->create(['career_proposal_id' => $this->proposal->id, 'department_id' => $this->cb->id]);

        $page = proposalPageAs($this->coordinator, ['vista' => 'tabla'])
            ->assertSee('Pendiente')
            ->assertDontSee('CB ya aceptó su parte')
            ->assertViewHas('locks', []);

        $cbPart->update(['status' => ProposalReviewStatus::Accepted, 'decided_by' => User::factory()->create()->id, 'decided_at' => now()]);

        $page->call('$refresh')
            ->assertSee('Aceptada')
            ->assertSee('CB ya aceptó su parte')
            ->assertViewHas('locks', fn (array $locks): bool => array_keys($locks) === [$this->calculus->id])
            ->call('saveCell', $this->calculus->id, 'requested_groups', '4');

        expect(lastToastOf($page)['type'])->toBe('error')
            ->and($this->calculus->fresh()->requested_groups)->toBe(2);

        $page->call('saveCell', $this->networks->id, 'requested_groups', '2');

        expect($this->networks->fresh()->requested_groups)->toBe(2);
    });

    it('no edita una propuesta enviada', function () {
        $this->proposal->update(['status' => ProposalStatus::Submitted]);

        $page = proposalPageAs($this->coordinator)->call('edit', $this->calculus->id)->call('save');

        expect($this->calculus->fresh()->requested_groups)->toBe(2);
        $page->assertSee('Enviada');
    });

    it('envía la propuesta con la confirmación y deja la bitácora', function () {
        $page = proposalPageAs($this->coordinator)
            ->call('confirmSubmit')
            ->assertSet('showSubmit', true)
            ->call('submit')
            ->assertSet('showSubmit', false);

        expect($this->proposal->fresh()->status)->toBe(ProposalStatus::Submitted)
            ->and(lastToastOf($page)['type'])->toBe('success')
            ->and(Activity::query()->where('event', 'submitted')->count())->toBe(1);
    });

    it('avisa de los catálogos sin validar de un departamento pero deja enviar', function () {
        proposalPageAs($this->coordinator)
            ->call('confirmSubmit')
            ->assertSee('Ciencias Básicas aún no valida')
            ->call('submit');

        expect($this->proposal->fresh()->status)->toBe(ProposalStatus::Submitted);
    });

    it('muestra el error de la Action al enviar sin grupos', function () {
        ProposalItem::query()->update(['requested_groups' => 0]);

        $page = proposalPageAs($this->coordinator)->call('confirmSubmit')->call('submit');

        expect($this->proposal->fresh()->status)->toBe(ProposalStatus::Draft)
            ->and(lastToastOf($page)['description'])->toContain('Pide al menos un grupo');
    });

    it('arma la propuesta al entrar si todavía no existe', function () {
        $other = Career::factory()->create(['code' => 'IND']);
        CareerCampus::factory()->create(['career_id' => $other->id, 'campus_id' => $this->campus->id]);
        $coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $other->id])->create();

        proposalPageAs($coordinator)->assertOk()->assertSee('IND');

        expect(CareerProposal::query()->where('career_id', $other->id)->count())->toBe(1);
    });

    it('con dos carreras ofrece un selector y busca la elegida por el id de la URL', function () {
        $other = Career::factory()->create(['code' => 'IND']);
        CareerCampus::factory()->create(['career_id' => $other->id, 'campus_id' => $this->campus->id]);
        $both = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->career->id])->create();
        $both->roleScopes()->create(['role' => Role::Coordinador, 'campus_id' => $this->campus->id, 'career_id' => $other->id, 'valid_from' => today()->subMonth()->toDateString()]);

        proposalPageAs($both, ['carrera' => $this->career->id])->assertSee('Cálculo Diferencial')->assertSee('IND');
        proposalPageAs($both, ['carrera' => 'no-es-uuid'])->assertForbidden();
    });

    it('sin periodo abierto en el campus dice que no hay propuesta', function () {
        TermOpening::query()->delete();

        proposalPageAs($this->coordinator)->assertSee('no está abierto en este campus')->assertDontSee('Cálculo Diferencial');
    });

    it('sin periodo activo lo dice', function () {
        Term::query()->update(['is_current' => false]);

        proposalPageAs($this->coordinator)->assertSee('No hay un periodo activo')->assertDontSee('Cálculo Diferencial');
    });
});

describe('alcance', function () {
    it('un coordinador de otra carrera no ve la propuesta ni la pide por la URL', function () {
        $other = Career::factory()->create(['code' => 'IND']);
        CareerCampus::factory()->create(['career_id' => $other->id, 'campus_id' => $this->campus->id]);
        $coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $other->id])->create();

        proposalPageAs($coordinator)->assertDontSee('Cálculo Diferencial');
        proposalPageAs($coordinator, ['carrera' => $this->career->id])->assertForbidden();
    });

    it('un coordinador de otro campus no la ve', function () {
        $c1 = Campus::factory()->create(['code' => 'C1']);
        TermOpening::factory()->create(['term_id' => $this->term->id, 'campus_id' => $c1->id]);
        $coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $c1->id, 'career_id' => $this->career->id])->create();

        proposalPageAs($coordinator)->assertDontSee('Cálculo Diferencial');
        proposalPageAs($coordinator, ['carrera' => $this->career->id])->assertForbidden();
    });

    it('un jefe de departamento y un docente reciben 403', function () {
        $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->cb->id])->create();
        $docente = User::factory()->withRole(Role::Docente, ['campus_id' => $this->campus->id])->create();

        proposalPageAs($jefe)->assertForbidden();
        proposalPageAs($docente)->assertForbidden();
    });

    it('un coordinador de otra carrera no edita ni envía llamando los métodos directo', function () {
        $other = Career::factory()->create(['code' => 'IND']);
        CareerCampus::factory()->create(['career_id' => $other->id, 'campus_id' => $this->campus->id]);
        $intruder = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $other->id])->create();

        $first = proposalPageAs($this->coordinator);
        $second = proposalPageAs($this->coordinator);
        $this->actingAs($intruder);

        $first->call('saveCell', $this->calculus->id, 'requested_groups', '9')->assertForbidden();
        $second->call('confirmSubmit')->assertForbidden();

        expect($this->calculus->fresh()->requested_groups)->toBe(2);
    });

    it('subdirección la ve completa, sin poder editar ni enviar', function () {
        proposalPageAs($this->subdirection, ['carrera' => $this->career->id])
            ->assertOk()
            ->assertSee('Cálculo Diferencial')
            ->assertDontSee('Enviar propuesta')
            ->call('saveCell', $this->calculus->id, 'requested_groups', '9')
            ->assertForbidden();

        proposalPageAs($this->subdirection, ['carrera' => $this->career->id])->call('confirmSubmit')->assertForbidden();
        proposalPageAs($this->subdirection, ['carrera' => $this->career->id])->call('edit', $this->calculus->id)->set('requested_groups', 5)->call('save')->assertForbidden();

        expect($this->calculus->fresh()->requested_groups)->toBe(2)
            ->and($this->proposal->fresh()->status)->toBe(ProposalStatus::Draft);
    });

    it('subdirección no arma una propuesta que no existe', function () {
        $other = Career::factory()->create(['code' => 'IND']);
        CareerCampus::factory()->create(['career_id' => $other->id, 'campus_id' => $this->campus->id]);

        proposalPageAs($this->subdirection, ['carrera' => $other->id])->assertSee('todavía no arma su propuesta');

        expect(CareerProposal::query()->where('career_id', $other->id)->exists())->toBeFalse();
    });

    it('subdirección elige campus cuando tiene varios', function () {
        Campus::factory()->create(['code' => 'C1']);

        proposalPageAs($this->subdirection)->assertSee('Elige un campus en tu credencial');
    });
});

describe('ruta y menú', function () {
    it('sirve la ruta al coordinador y manda al invitado al login', function () {
        $this->get(route('academic-load.proposal'))->assertRedirect(route('login'));
        $this->actingAs($this->coordinator)->get(route('academic-load.proposal'))->assertOk()->assertSee('Propuesta de carrera');
    });

    it('la entrada del menú la ven coordinador, subdirección y admin, y no un jefe', function () {
        $labels = fn (User $user): array => array_map(
            fn ($item) => $item->label,
            (function () use ($user) {
                $this->actingAs($user);

                return app(Navigation::class)->visibleTo(app(ScopedContext::class));
            })->call($this),
        );

        $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->cb->id])->create();

        expect($labels($this->coordinator))->toContain('Propuesta de carrera')
            ->and($labels($this->subdirection))->toContain('Propuesta de carrera')
            ->and($labels(User::factory()->admin()->create()))->toContain('Propuesta de carrera')
            ->and($labels($jefe))->not->toContain('Propuesta de carrera');
    });
});

describe('solo lectura, propuesta fija y rechazos', function () {
    it('a subdirección la tabla le deshabilita todos los campos', function () {
        $html = proposalPageAs($this->subdirection, ['carrera' => $this->career->id, 'vista' => 'tabla'])->assertSeeHtml('data-field="requested_groups"')->html();

        preg_match_all('/<input[^>]*type="number"[^>]*>/', $html, $inputs);

        expect($inputs[0])->not->toBeEmpty()
            ->and(array_filter($inputs[0], fn (string $input): bool => ! str_contains($input, 'disabled')))->toBeEmpty();
    });

    it('al coordinador la tabla le deja los campos de las filas editables', function () {
        $html = proposalPageAs($this->coordinator, ['vista' => 'tabla'])->html();

        preg_match_all('/<input[^>]*type="number"[^>]*>/', $html, $inputs);

        expect(array_filter($inputs[0], fn (string $input): bool => ! str_contains($input, 'disabled')))->not->toBeEmpty();
    });

    it('sigue pintando y escribiendo en la propuesta con la que entró aunque la credencial cambie', function () {
        $otherTerm = Term::factory()->create(['code' => '20273']);
        $page = proposalPageAs($this->coordinator)->assertSee('Cálculo Diferencial');

        Term::query()->update(['is_current' => false]);
        $otherTerm->update(['is_current' => true]);

        $page->call('setView', 'tabla')->assertSee('Cálculo Diferencial')->call('saveCell', $this->calculus->id, 'requested_groups', '5');

        expect($this->calculus->fresh()->requested_groups)->toBe(5);
    });

    it('tras un rechazo repinta la fila para que el campo vuelva al valor guardado', function () {
        $key = fn (int $rejections): string => "row-{$this->calculus->id}-{$rejections}-";
        $page = proposalPageAs($this->coordinator, ['vista' => 'tabla'])->assertSeeHtml($key(0));

        $page->call('saveCell', $this->calculus->id, 'requested_groups', '99')
            ->assertSeeHtml($key(1))
            ->assertDontSeeHtml($key(0))
            ->assertSeeHtml('value="2" ');

        expect($this->calculus->fresh()->requested_groups)->toBe(2);
    });

    it('calcula los catálogos pendientes solo al abrir la confirmación de envío', function () {
        proposalPageAs($this->coordinator)
            ->assertViewHas('overview', fn ($overview): bool => $overview->pendingCatalogs === [])
            ->call('confirmSubmit')
            ->assertViewHas('overview', fn ($overview): bool => count($overview->pendingCatalogs) === 2);
    });

    it('no envía si el periodo se cerró en el campus y lo dice', function () {
        $page = proposalPageAs($this->coordinator);
        TermOpening::query()->delete();

        $page->call('confirmSubmit')->call('submit');

        expect($this->proposal->fresh()->status)->toBe(ProposalStatus::Draft)
            ->and(lastToastOf($page)['description'])->toContain('no está abierto');
    });
});
