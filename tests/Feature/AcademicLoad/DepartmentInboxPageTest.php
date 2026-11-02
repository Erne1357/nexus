<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\AcademicLoad\Rules\ProposalReviewRules;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Queries\ScopedContext;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| «Bandeja del departamento» (tarea 2.8): el jefe ve lo que piden las carreras de su campus al departamento en el
| periodo activo, acepta o regresa cada parte y ve su carga pasar a Propuesta (reglas-de-negocio.md, "Propuesta de
| carrera", HC-14).
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create(['code' => 'C2', 'settings' => [...CampusProfile::DEFAULTS, 'uses_rooms' => false]]);
    $this->term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    TermOpening::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id]);
    $this->cb = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $this->sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->load = AcademicLoad::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'department_id' => $this->cb->id]);
    $this->calculus = Subject::factory()->create(['code' => 'ACF-0901', 'name' => 'Cálculo Diferencial', 'department_id' => $this->cb->id, 'needs_lab' => false]);

    $this->isc = inboxPageCareer('ISC', 'Sistemas');
    $this->iind = inboxPageCareer('IIND', 'Industrial');
    $this->iscProposal = inboxPageProposal($this->isc, 60, 2);
    $this->iindProposal = inboxPageProposal($this->iind, 40, 2);
    $this->iscPart = ProposalReview::factory()->create(['career_proposal_id' => $this->iscProposal->id, 'department_id' => $this->cb->id]);
    $this->iindPart = ProposalReview::factory()->create(['career_proposal_id' => $this->iindProposal->id, 'department_id' => $this->cb->id]);

    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->cb->id])->create();
    inboxPageValidate($this->cb);
});

function inboxPageCareer(string $code, string $name): Career
{
    $career = Career::factory()->create(['code' => $code, 'name' => $name]);
    CareerCampus::factory()->create(['career_id' => $career->id, 'campus_id' => test()->campus->id]);

    return $career;
}

function inboxPageProposal(Career $career, int $expected, int $groups): CareerProposal
{
    $proposal = CareerProposal::factory()->submitted()->create(['term_id' => test()->term->id, 'campus_id' => test()->campus->id, 'career_id' => $career->id]);
    ProposalItem::factory()->create([
        'career_proposal_id' => $proposal->id,
        'subject_id' => test()->calculus->id,
        'semester' => 1,
        'eligible_students' => $expected,
        'expected_students' => $expected,
        'requested_groups' => $groups,
        'notes' => "Nota de {$career->code}",
    ]);

    return $proposal;
}

function inboxPageValidate(Department $department): void
{
    foreach (Catalog::general() as $catalog) {
        CatalogReview::factory()->general($catalog)->validated()->create(['term_id' => test()->term->id, 'department_id' => $department->id]);
    }
}

/**
 * @param  array<string, string>  $query
 */
function inboxPageAs(User $user, array $query = []): Testable
{
    return Livewire::withQueryParams($query)->actingAs($user)->test('academic-load::pages.inbox');
}

describe('jefe de departamento', function () {
    it('ve su departamento, el campus, el periodo por su nombre y el estado de su carga', function () {
        inboxPageAs($this->jefe)
            ->assertOk()
            ->assertSee('Ciencias Básicas')
            ->assertSee('Ene-Jun 2027')
            ->assertDontSee('20271')
            ->assertSee('Borrador')
            ->assertSee('ISC')
            ->assertSee('IIND');
    });

    it('lista las carreras con su estado y elige la primera pendiente', function () {
        $this->iscPart->update(['status' => ProposalReviewStatus::Accepted]);

        inboxPageAs($this->jefe)
            ->assertSet('careerId', '')
            ->assertViewHas('selected', fn (array $row): bool => $row['career']->code === 'IIND')
            ->assertSee('Aceptada')
            ->assertSee('Pendiente');
    });

    it('abre la carrera de ?carrera y el departamento de ?depto, como los manda la notificación', function () {
        inboxPageAs($this->jefe, ['depto' => 'CB', 'carrera' => $this->isc->id])
            ->assertSet('careerId', $this->isc->id)
            ->assertSet('department', 'CB')
            ->assertViewHas('selected', fn (array $row): bool => $row['career']->code === 'ISC')
            ->assertSee('Nota de ISC');
    });

    it('filtra a las carreras pendientes', function () {
        $this->iscPart->update(['status' => ProposalReviewStatus::Accepted]);

        inboxPageAs($this->jefe)
            ->set('filter', 'pendientes')
            ->assertViewHas('visible', fn (array $rows): bool => count($rows) === 1 && $rows[0]['career']->code === 'IIND');
    });

    it('muestra el frente al total de la materia en una tabla compacta, sin barras', function () {
        inboxPageAs($this->jefe, ['carrera' => $this->isc->id])
            ->assertSee('Cálculo Diferencial')
            ->assertSee('ACF-0901')
            ->assertSeeText('2 de 4 grupos · 2 carreras')
            ->assertSee('Nota de ISC')
            ->assertSeeHtml('data-testid="inbox-items"')
            ->assertDontSeeHtml('<progress')
            ->assertSeeHtml('data-testid="inbox-detail-header"')
            ->assertSeeHtml('data-testid="inbox-detail-total"');
    });

    it('ofrece los paralelos como un chip con el ahorro, y su detalle por carrera y los grupos mixtos', function () {
        inboxPageAs($this->jefe, ['carrera' => $this->isc->id])
            ->assertSeeHtml('data-testid="parallels-chip"')
            ->assertSee('−1 grupo juntando carreras')
            ->assertDontSee('Piden 4')
            ->assertSet('showParallels', false)
            ->call('openParallels', $this->calculus->id)
            ->assertSet('showParallels', true)
            ->assertSeeHtml('data-testid="parallels-drawer"')
            ->assertSee('Cálculo Diferencial')
            ->assertSee('Piden 4')
            ->assertSee('bastan 3')
            ->assertSeeHtml('data-testid="parallels-table"')
            ->assertSee('1 mixto')
            ->assertSee('25 de ISC')
            ->assertSee('5 de IIND');
    });

    it('no ofrece el chip de paralelos en una materia que pide una sola carrera', function () {
        $this->iindPart->update(['status' => ProposalReviewStatus::Returned, 'comment' => 'Revisa.']);
        $this->iindProposal->update(['status' => ProposalStatus::Returned]);

        inboxPageAs($this->jefe, ['carrera' => $this->isc->id])->assertDontSeeHtml('data-testid="parallels-chip"');
    });

    it('no abre los paralelos de una materia que no está en la bandeja', function () {
        $other = Subject::factory()->create(['department_id' => $this->sc->id]);

        inboxPageAs($this->jefe)->call('openParallels', $other->id)->assertNotFound();
    });

    it('al aceptar pasa a la siguiente carrera por decidir', function () {
        inboxPageAs($this->jefe, ['carrera' => $this->isc->id])
            ->call('confirmAccept', $this->iscPart->id)
            ->call('accept')
            ->assertSet('careerId', $this->iind->id)
            ->assertViewHas('selected', fn (array $row): bool => $row['career']->code === 'IIND');
    });

    it('al regresar pasa a la siguiente carrera por decidir', function () {
        inboxPageAs($this->jefe, ['carrera' => $this->iind->id])
            ->call('confirmReturn', $this->iindPart->id)
            ->set('comment', 'Corrige.')
            ->call('returnPart')
            ->assertSet('careerId', $this->isc->id);
    });

    it('al decidir la última por decidir se queda en esa carrera', function () {
        $this->iscPart->update(['status' => ProposalReviewStatus::Accepted]);

        inboxPageAs($this->jefe, ['carrera' => $this->iind->id])
            ->call('confirmAccept', $this->iindPart->id)
            ->call('accept')
            ->assertViewHas('selected', fn (array $row): bool => $row['career']->code === 'IIND');
    });

    it('la lista de carreras y el comentario de regresada no llevan borde lateral de color', function () {
        $this->iscPart->update(['status' => ProposalReviewStatus::Returned, 'comment' => 'Revisa los esperados.']);
        $this->iscProposal->update(['status' => ProposalStatus::Returned]);

        inboxPageAs($this->jefe, ['carrera' => $this->isc->id])
            ->assertDontSeeHtml('border-l-4')
            ->assertSeeHtml('data-testid="part-comment"')
            ->assertSee('Comentario de Ciencias Básicas')
            ->assertSee('Revisa los esperados.');
    });

    it('marca las anomalías de la fila y cuenta las de cada carrera, sin bloquear', function () {
        ProposalItem::query()->where('career_proposal_id', $this->iscProposal->id)->update(['eligible_students' => 98, 'expected_students' => 105]);

        inboxPageAs($this->jefe, ['carrera' => $this->isc->id])
            ->assertSee('105 esperados con 98 elegibles')
            ->assertSeeHtml('data-testid="row-anomalies"')
            ->assertViewHas('anomalyCounts', fn (array $counts): bool => ($counts[$this->isc->id] ?? 0) === 1 && ($counts[$this->iind->id] ?? 0) === 0)
            ->assertSeeHtml('data-testid="career-anomalies"')
            ->assertSeeHtml('data-testid="accept-part"')
            ->call('confirmAccept', $this->iscPart->id)
            ->call('accept')
            ->assertHasNoErrors();
    });

    it('no marca anomalías cuando no las hay', function () {
        inboxPageAs($this->jefe, ['carrera' => $this->isc->id])
            ->assertDontSeeHtml('data-testid="row-anomalies"')
            ->assertDontSeeHtml('data-testid="career-anomalies"');
    });

    it('la confirmación de aceptar destaca que lo aceptado ya no cambia', function () {
        inboxPageAs($this->jefe, ['carrera' => $this->isc->id])
            ->call('confirmAccept', $this->iscPart->id)
            ->assertSeeHtml('data-testid="accept-warning"')
            ->assertSee('Lo aceptado ya no cambia.');
    });

    it('regresa desde un drawer con el contador de caracteres y las anomalías de la carrera', function () {
        ProposalItem::query()->where('career_proposal_id', $this->iscProposal->id)->update(['eligible_students' => 98, 'expected_students' => 105]);

        inboxPageAs($this->jefe, ['carrera' => $this->isc->id])
            ->call('confirmReturn', $this->iscPart->id)
            ->assertSeeHtml('data-testid="return-drawer"')
            ->assertSeeHtml('data-testid="return-anomalies"')
            ->assertSee('105 esperados con 98 elegibles')
            ->assertSeeHtml('data-testid="return-counter"')
            ->assertSeeHtml('maxlength="'.ProposalReviewRules::MAX_COMMENT_LENGTH.'"')
            ->assertSeeHtml('/ '.ProposalReviewRules::MAX_COMMENT_LENGTH)
            ->assertDontSeeHtml('wire:model.live.debounce')
            ->assertSeeHtml('data-testid="return-cite"');
    });

    it('el chip de paralelos con cero esperados no presume un ahorro', function () {
        ProposalItem::query()->update(['expected_students' => 0]);

        inboxPageAs($this->jefe, ['carrera' => $this->isc->id])
            ->assertSeeHtml('data-testid="parallels-chip"')
            ->assertSee('Sin alumnos esperados')
            ->assertDontSee('−4 grupos');
    });

    it('el chip de paralelos con un resto de una sola carrera no dice mixtos', function () {
        ProposalItem::query()->where('career_proposal_id', $this->iindProposal->id)->update(['expected_students' => 70]);

        inboxPageAs($this->jefe, ['carrera' => $this->isc->id])
            ->assertSeeHtml('data-testid="parallels-chip"')
            ->assertSee('1 grupo más de ISC')
            ->assertDontSee('Con grupos mixtos');
    });

    it('al decidir, el aviso nombra la carrera y lo que se hizo, y el panel se limpia', function () {
        $accepted = inboxPageAs($this->jefe, ['carrera' => $this->isc->id])
            ->call('confirmAccept', $this->iscPart->id)
            ->call('accept')
            ->assertSet('reviewId', null);
        $returned = inboxPageAs($this->jefe, ['carrera' => $this->iind->id])
            ->call('confirmReturn', $this->iindPart->id)
            ->set('comment', 'Corrige.')
            ->call('returnPart')
            ->assertSet('reviewId', null);

        expect(json_encode($accepted->effects['xjs'] ?? []))->toContain('Aceptaste la parte de ISC')
            ->and(json_encode($returned->effects['xjs'] ?? []))->toContain('Regresaste la parte de IIND');
    });

    it('cerrar el drawer de regresar suelta la parte', function () {
        inboxPageAs($this->jefe, ['carrera' => $this->isc->id])
            ->call('confirmReturn', $this->iscPart->id)
            ->assertSet('reviewId', $this->iscPart->id)
            ->set('showReturn', false)
            ->assertSet('reviewId', null);
    });

    it('acepta una parte, lo dice en un aviso y lleva la carga a Propuesta', function () {
        inboxPageAs($this->jefe, ['carrera' => $this->isc->id])
            ->call('confirmAccept', $this->iscPart->id)
            ->assertSet('showAccept', true)
            ->assertSee('60')
            ->call('accept')
            ->assertSet('showAccept', false)
            ->assertSee('Propuesta');

        expect($this->iscPart->fresh()->status)->toBe(ProposalReviewStatus::Accepted)
            ->and($this->load->fresh()->status)->toBe(LoadStatus::Proposed);
    });

    it('regresa una parte con comentario y la propuesta vuelve al coordinador', function () {
        inboxPageAs($this->jefe, ['carrera' => $this->isc->id])
            ->call('confirmReturn', $this->iscPart->id)
            ->assertSet('showReturn', true)
            ->set('comment', 'Faltan grupos de laboratorio.')
            ->call('returnPart')
            ->assertSet('showReturn', false)
            ->assertSee('Regresada')
            ->call('pick', $this->isc->id)
            ->assertSee('Faltan grupos de laboratorio.');

        expect($this->iscPart->fresh())->status->toBe(ProposalReviewStatus::Returned)->comment->toBe('Faltan grupos de laboratorio.')
            ->and($this->iscProposal->fresh()->status)->toBe(ProposalStatus::Returned);
    });

    it('exige el comentario para regresar y lo dice junto al campo', function () {
        inboxPageAs($this->jefe, ['carrera' => $this->isc->id])
            ->call('confirmReturn', $this->iscPart->id)
            ->set('comment', '   ')
            ->call('returnPart')
            ->assertHasErrors('comment')
            ->assertSet('showReturn', true);

        expect($this->iscPart->fresh()->status)->toBe(ProposalReviewStatus::Pending);
    });

    it('no pasa de 1000 caracteres de comentario', function () {
        inboxPageAs($this->jefe, ['carrera' => $this->isc->id])
            ->call('confirmReturn', $this->iscPart->id)
            ->set('comment', str_repeat('a', 1001))
            ->call('returnPart')
            ->assertHasErrors('comment');
    });

    it('una parte aceptada o regresada no ofrece botones y muestra el comentario', function () {
        $this->iscPart->update(['status' => ProposalReviewStatus::Returned, 'comment' => 'Revisa los esperados.']);
        $this->iscProposal->update(['status' => ProposalStatus::Returned]);

        inboxPageAs($this->jefe, ['carrera' => $this->isc->id])
            ->assertSee('Revisa los esperados.')
            ->assertDontSeeHtml('data-testid="accept-part"')
            ->assertDontSeeHtml('data-testid="return-part"');

        $this->iindPart->update(['status' => ProposalReviewStatus::Accepted]);

        inboxPageAs($this->jefe, ['carrera' => $this->iind->id])->assertSee('Aceptada')->assertDontSeeHtml('data-testid="accept-part"');
    });

    it('no acepta una parte que ya no se puede decidir y lo dice', function () {
        $page = inboxPageAs($this->jefe, ['carrera' => $this->isc->id])->call('confirmAccept', $this->iscPart->id);
        $this->iscPart->update(['status' => ProposalReviewStatus::Accepted]);

        $page->call('accept')->assertHasErrors('status');
    });
});

describe('lo que se decide y se cuenta', function () {
    it('abre por omisión la primera parte que se puede decidir y solo esas cuentan como pendientes', function () {
        $this->iindPart->update(['status' => ProposalReviewStatus::Returned, 'comment' => 'Revisa los esperados.']);
        $this->iindProposal->update(['status' => ProposalStatus::Returned]);

        inboxPageAs($this->jefe)
            ->assertViewHas('selected', fn (array $row): bool => $row['career']->code === 'ISC')
            ->assertViewHas('pendingCount', 1)
            ->set('filter', 'pendientes')
            ->assertViewHas('visible', fn (array $rows): bool => count($rows) === 1 && $rows[0]['career']->code === 'ISC');
    });

    describe('con la propuesta regresada por otro departamento', function () {
        beforeEach(function () {
            $this->iscPart->update(['status' => ProposalReviewStatus::Accepted]);
            $this->iindProposal->update(['status' => ProposalStatus::Returned]);
            ProposalReview::factory()->returned('Pide un grupo más de Redes.')->create(['career_proposal_id' => $this->iindProposal->id, 'department_id' => $this->sc->id]);
        });

        it('la parte pendiente se abre por omisión, entra en Pendientes y ofrece Aceptar y Regresar', function () {
            inboxPageAs($this->jefe)
                ->assertViewHas('selected', fn (array $row): bool => $row['career']->code === 'IIND')
                ->assertViewHas('pendingCount', 1)
                ->assertViewHas('canDecide', true)
                ->assertSee('Pendiente')
                ->assertSeeHtml('data-testid="accept-part"')
                ->assertSeeHtml('data-testid="return-part"')
                ->assertSeeHtml('data-testid="proposal-in-correction"')
                ->set('filter', 'pendientes')
                ->assertViewHas('visible', fn (array $rows): bool => count($rows) === 1 && $rows[0]['career']->code === 'IIND');
        });

        it('aceptarla la deja aceptada y la propuesta sigue regresada', function () {
            inboxPageAs($this->jefe, ['carrera' => $this->iind->id])
                ->call('confirmAccept', $this->iindPart->id)
                ->call('accept')
                ->assertHasNoErrors()
                ->assertSet('showAccept', false)
                ->assertDontSeeHtml('data-testid="accept-part"');

            expect($this->iindPart->fresh()->status)->toBe(ProposalReviewStatus::Accepted)
                ->and($this->iindProposal->fresh()->status)->toBe(ProposalStatus::Returned);
        });

        it('regresarla la deja regresada con su comentario y la propuesta sigue regresada', function () {
            inboxPageAs($this->jefe, ['carrera' => $this->iind->id])
                ->call('confirmReturn', $this->iindPart->id)
                ->set('comment', 'Sin docentes para Cálculo.')
                ->call('returnPart')
                ->assertHasNoErrors()
                ->assertSee('Sin docentes para Cálculo.');

            expect($this->iindPart->fresh())->status->toBe(ProposalReviewStatus::Returned)->comment->toBe('Sin docentes para Cálculo.')
                ->and($this->iindProposal->fresh()->status)->toBe(ProposalStatus::Returned);
        });
    });

    it('una parte pendiente de una propuesta enviada no muestra el aviso de la propuesta en corrección', function () {
        inboxPageAs($this->jefe, ['carrera' => $this->isc->id])
            ->assertSeeHtml('data-testid="accept-part"')
            ->assertDontSeeHtml('data-testid="proposal-in-correction"');
    });

    it('no decide una parte de otro campus ni de otro periodo aunque llegue su id', function () {
        $otherCampus = Campus::factory()->create(['code' => 'C1']);
        $otherTerm = Term::factory()->create(['code' => '20272']);
        $career = inboxPageCareer('IGEM', 'Gestión');
        $elsewhere = CareerProposal::factory()->submitted()->create(['term_id' => $this->term->id, 'campus_id' => $otherCampus->id, 'career_id' => $career->id]);
        $later = CareerProposal::factory()->submitted()->create(['term_id' => $otherTerm->id, 'campus_id' => $this->campus->id, 'career_id' => $career->id]);
        $foreign = [
            ProposalReview::factory()->create(['career_proposal_id' => $elsewhere->id, 'department_id' => $this->cb->id]),
            ProposalReview::factory()->create(['career_proposal_id' => $later->id, 'department_id' => $this->cb->id]),
        ];

        foreach ($foreign as $part) {
            inboxPageAs($this->jefe)->call('confirmAccept', $part->id)->assertNotFound();
            inboxPageAs($this->jefe)->call('confirmReturn', $part->id)->assertNotFound();
            expect($part->fresh()->status)->toBe(ProposalReviewStatus::Pending);
        }
    });

    it('conserva la bandeja de un departamento desactivado que tiene partes en el periodo y campus', function () {
        $this->cb->update(['is_active' => false]);

        inboxPageAs($this->jefe)->assertOk()->assertSee('Ciencias Básicas')->assertSee('ISC')->assertViewHas('canDecide', true);
    });
});

describe('textos de la sugerencia de paralelos y del detalle', function () {
    it('dice de qué carrera es cada resto cuando un grupo es de una sola carrera', function () {
        ProposalItem::query()->where('career_proposal_id', $this->iindProposal->id)->update(['expected_students' => 70]);

        inboxPageAs($this->jefe)->call('openParallels', $this->calculus->id)->assertSee('1 más de ISC (25)')->assertDontSee('1 mixto');
    });

    it('sin alumnos esperados no deja el texto cortado', function () {
        ProposalItem::query()->update(['expected_students' => 0]);

        inboxPageAs($this->jefe)->call('openParallels', $this->calculus->id)->assertSee('Piden 4 · sin alumnos esperados')->assertDontSee('bastan 0');
    });

    it('distingue la carrera sin propuesta de la que no le pide materias al departamento', function () {
        $sent = inboxPageCareer('IGEM', 'Gestión');
        CareerProposal::factory()->submitted()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'career_id' => $sent->id]);
        $draft = inboxPageCareer('ILOG', 'Logística');
        CareerProposal::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'career_id' => $draft->id]);
        $none = inboxPageCareer('IEM', 'Electromecánica');

        inboxPageAs($this->jefe, ['carrera' => $sent->id])->assertSee('No le pide materias a Ciencias Básicas.')->assertDontSee('Todavía no envía');
        inboxPageAs($this->jefe, ['carrera' => $draft->id])->assertSee('Todavía no envía su propuesta.');
        inboxPageAs($this->jefe, ['carrera' => $none->id])->assertSee('Todavía no envía su propuesta.');
    });
});

describe('HC-14: aceptar bloqueado sin validación', function () {
    beforeEach(function () {
        CatalogReview::query()->where('department_id', $this->cb->id)->where('catalog', Catalog::Teachers)->delete();
    });

    it('avisa de los catálogos pendientes con el enlace, y deshabilita aceptar con su motivo', function () {
        inboxPageAs($this->jefe, ['carrera' => $this->isc->id])
            ->assertSeeHtml('data-testid="pending-catalogs"')
            ->assertSee(mb_strtolower(Catalog::Teachers->label()))
            ->assertSeeHtml('data-testid="accept-part"')
            ->assertSeeHtml('disabled');
    });

    it('no acepta aunque se llame el método directo, y deja la parte pendiente', function () {
        inboxPageAs($this->jefe, ['carrera' => $this->isc->id])
            ->call('confirmAccept', $this->iscPart->id)
            ->call('accept')
            ->assertHasErrors('catalogs')
            ->assertSet('showAccept', false);

        expect($this->iscPart->fresh()->status)->toBe(ProposalReviewStatus::Pending)
            ->and($this->load->fresh()->status)->toBe(LoadStatus::Draft);
    });

    it('sí deja regresar', function () {
        inboxPageAs($this->jefe, ['carrera' => $this->isc->id])
            ->call('confirmReturn', $this->iscPart->id)
            ->set('comment', 'Corrige.')
            ->call('returnPart')
            ->assertHasNoErrors();

        expect($this->iscPart->fresh()->status)->toBe(ProposalReviewStatus::Returned);
    });
});

describe('estados vacíos', function () {
    it('sin periodo activo lo dice', function () {
        Term::query()->update(['is_current' => false]);

        inboxPageAs($this->jefe)->assertSeeHtml('data-testid="inbox-empty"')->assertSee('No hay un periodo activo');
    });

    it('sin periodo abierto en el campus lo dice', function () {
        TermOpening::query()->delete();

        inboxPageAs($this->jefe)->assertSee('no está abierto en este campus');
    });

    it('sin carreras con propuesta enviada lo dice', function () {
        ProposalReview::query()->delete();

        inboxPageAs($this->jefe)->assertSeeHtml('data-testid="inbox-empty-careers"');
    });
});

describe('alcance', function () {
    it('un jefe de otro departamento no ve esta bandeja ni la pide por la URL', function () {
        $otro = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->sc->id])->create();

        inboxPageAs($otro)->assertSet('department', 'SC')->assertDontSee('Ciencias Básicas')->assertDontSee('Nota de ISC');
        inboxPageAs($otro, ['depto' => 'CB'])->assertForbidden();
    });

    it('un jefe de otro departamento no decide las partes de este llamando los métodos directo', function () {
        $otro = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->sc->id])->create();

        $page = inboxPageAs($this->jefe, ['carrera' => $this->isc->id])->call('confirmAccept', $this->iscPart->id);
        $other = inboxPageAs($this->jefe);
        $this->actingAs($otro);
        $page->call('accept')->assertForbidden();
        $other->call('confirmReturn', $this->iscPart->id)->assertForbidden();

        expect($this->iscPart->fresh()->status)->toBe(ProposalReviewStatus::Pending);
    });

    it('un jefe de otro campus no ve la bandeja', function () {
        $otroCampus = Campus::factory()->create();
        $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $otroCampus->id, 'department_id' => $this->cb->id])->create();

        inboxPageAs($jefe)->assertDontSee('Nota de ISC');
    });

    it('un coordinador y un docente reciben 403', function () {
        $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->isc->id])->create();
        $docente = User::factory()->withRole(Role::Docente, ['campus_id' => $this->campus->id])->create();

        inboxPageAs($coordinador)->assertForbidden();
        inboxPageAs($docente)->assertForbidden();
    });

    it('subdirección la ve con un selector de departamento, sin poder decidir', function () {
        $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();

        inboxPageAs($subdireccion, ['depto' => 'CB', 'carrera' => $this->isc->id])
            ->assertOk()
            ->assertSee('Nota de ISC')
            ->assertSeeHtml('data-testid="inbox-department"')
            ->assertDontSeeHtml('data-testid="accept-part"')
            ->call('confirmAccept', $this->iscPart->id)
            ->assertForbidden();
    });

    it('el admin la ve de cualquier departamento', function () {
        inboxPageAs(User::factory()->admin()->create(), ['depto' => 'CB', 'carrera' => $this->isc->id])->assertOk()->assertSee('Nota de ISC');
    });

    it('un jefe con un solo departamento no ve el selector', function () {
        inboxPageAs($this->jefe)->assertDontSeeHtml('data-testid="inbox-department"');
    });

    it('un jefe que cubre dos departamentos los elige y cambia de bandeja', function () {
        $jefe = User::factory()
            ->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->cb->id])
            ->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->sc->id])
            ->create();

        inboxPageAs($jefe)->assertSeeHtml('data-testid="inbox-department"')->set('department', 'SC')->assertDontSee('Nota de ISC');
    });
});

describe('ruta y menú', function () {
    it('sirve la ruta al jefe y manda al invitado al login', function () {
        $this->get(route('academic-load.inbox'))->assertRedirect(route('login'));
        $this->actingAs($this->jefe)->get(route('academic-load.inbox'))->assertOk()->assertSee('Bandeja del departamento');
    });

    it('la entrada del menú la ven el jefe, el de proyecto docente, subdirección y admin, y no un coordinador', function () {
        $labels = function (User $user): array {
            $this->actingAs($user);

            return array_map(fn ($item) => $item->label, app(Navigation::class)->visibleTo(app(ScopedContext::class)->forUser($user)));
        };

        $proyecto = User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->campus->id, 'department_id' => $this->cb->id])->create();
        $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->isc->id])->create();

        expect($labels($this->jefe))->toContain('Bandeja del departamento')
            ->and($labels($proyecto))->toContain('Bandeja del departamento')
            ->and($labels(User::factory()->withRole(Role::Subdireccion)->create()))->toContain('Bandeja del departamento')
            ->and($labels(User::factory()->admin()->create()))->toContain('Bandeja del departamento')
            ->and($labels($coordinador))->not->toContain('Bandeja del departamento');
    });
});
