<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\Proposals\CreateProposalPackage;
use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalIntake;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalPackage;
use App\Modules\AcademicLoad\Models\ProposalPackageCampus;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| Los paquetes de primer semestre en la propuesta de carrera (tarea 5.6b, la pantalla; opción C, el mismo bloque en las tres
| maquetas): arriba del semestre 1, una columna por campus con el nuevo ingreso (lo de Nexus y de dónde sale), los paquetes que
| sugiere Nexus, cuántos abre y si alcanza; la lista de paquetes con sus materias y la cuenta de cada campus (M7: la de otro
| campus es de solo lectura); «Agregar paquete» y el panel del paquete. La composición es de la carrera; cada campus captura
| cuántos abre de cada uno. Enviada o aceptada, todo de solo lectura.
*/

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    TermOpening::factory()->create(['term_id' => $this->term->id, 'modality_id' => modalityOf('PRES')->id]);
    $this->career = Career::factory()->create(['code' => 'ISC', 'name' => 'Sistemas']);
    CareerCampus::factory()->create(['career_id' => $this->career->id, 'campus_id' => $this->c1->id]);
    CareerCampus::factory()->create(['career_id' => $this->career->id, 'campus_id' => $this->c2->id]);
    $this->cb = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $this->sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->proposal = CareerProposal::factory()->create(['term_id' => $this->term->id, 'career_id' => $this->career->id]);

    $this->calculus = packagesPageSubject('ACF-0901', 'Cálculo Diferencial', 1, $this->cb);
    $this->programming = packagesPageSubject('SCD-1008', 'Fundamentos de Programación', 1, $this->sc, lab: true);
    $this->integral = packagesPageSubject('ACF-0902', 'Cálculo Integral', 2, $this->cb);

    ProposalIntake::factory()->create(['career_proposal_id' => $this->proposal->id, 'campus_id' => $this->c1->id, 'suggested_new_students' => 171, 'new_students' => 171]);
    ProposalIntake::factory()->create(['career_proposal_id' => $this->proposal->id, 'campus_id' => $this->c2->id, 'suggested_new_students' => 64, 'new_students' => 64]);

    $this->ofC1 = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c1->id, 'career_id' => $this->career->id])->create(['name' => 'Héctor Villalobos']);
    $this->ofC2 = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->career->id])->create(['name' => 'Daniela Quintero']);
    $this->subdirection = User::factory()->withRole(Role::Subdireccion)->create();
});

/**
 * Una materia de la propuesta con su fila corregida en C1 y en C2: 30 esperados y un grupo suelto.
 */
function packagesPageSubject(string $code, string $name, int $semester, Department $department, bool $lab = false): Subject
{
    $subject = Subject::factory()->create(['code' => $code, 'name' => $name, 'department_id' => $department->id, 'needs_lab' => $lab]);

    foreach ([test()->c1, test()->c2] as $campus) {
        ProposalItem::factory()->create([
            'career_proposal_id' => test()->proposal->id,
            'campus_id' => $campus->id,
            'subject_id' => $subject->id,
            'semester' => $semester,
            'expected_students' => 30,
            'requested_groups' => 1,
            'captured_at' => now(),
        ]);
    }

    return $subject;
}

/**
 * El paquete armado con la Action, como desde la página: el primero nace con los paquetes que cubren el nuevo ingreso de
 * cada campus (171 en C1 da 5 de 35; 64 en C2 da 2).
 *
 * @param  list<Subject>  $subjects
 */
function packagesPagePackage(array $subjects, ?string $name = null): ProposalPackage
{
    return app(CreateProposalPackage::class)->handle(test()->proposal, array_map(fn (Subject $subject): string => $subject->id, $subjects), $name, test()->ofC1);
}

/**
 * La fila de una materia en un campus.
 */
function packagesPageItem(Subject $subject, Campus $campus): ProposalItem
{
    return ProposalItem::query()->where('career_proposal_id', test()->proposal->id)->where('subject_id', $subject->id)->where('campus_id', $campus->id)->sole();
}

/**
 * Cuántos paquetes de `$package` abre `$campus`.
 */
function packagesPageCount(ProposalPackage $package, Campus $campus): int
{
    return ProposalPackageCampus::query()->where('proposal_package_id', $package->id)->where('campus_id', $campus->id)->sole()->count;
}

/**
 * @param  array<string, string>  $query
 */
function packagesPageAs(User $user, array $query = ['vista' => 'tabla']): Testable
{
    return Livewire::withQueryParams($query)->actingAs($user)->test('academic-load::pages.proposal');
}

/**
 * El texto de un elemento de la página por su `data-testid`.
 */
function packagesPageText(Testable $page, string $testId): string
{
    return textOf(domOf($page)->querySelector("[data-testid=\"{$testId}\"]"));
}

describe('el bloque y sus cifras', function () {
    it('va arriba de las materias, con el nuevo ingreso de cada campus, lo que sugiere Nexus, cuántos abre y si alcanza', function () {
        packagesPagePackage([$this->calculus, $this->programming]);

        $page = packagesPageAs($this->ofC1)
            ->assertSeeHtml('data-testid="packages"')
            ->assertSee('Paquetes de primer semestre')
            ->assertSee('Cada paquete abre un grupo de cada una de sus materias.');

        expect(strpos($page->html(), 'data-testid="packages"'))->toBeLessThan(strpos($page->html(), 'data-testid="proposal-table"'))
            ->and(packagesPageText($page, 'packages-column-C1'))->toContain('Campus I')->toContain('Nuevo ingreso')->toContain('Abres')
            ->and(packagesPageText($page, 'packages-suggested-C1'))->toBe('5')
            ->and(packagesPageText($page, 'packages-open-C1'))->toBe('5')
            ->and(packagesPageText($page, 'packages-status-C1'))->toBe('Alcanza: 175 lugares')
            ->and(packagesPageText($page, 'intake-source-C1'))->toBe('Nexus 171 · promedio de los últimos 4 Ene-Jun')
            ->and(packagesPageText($page, 'packages-column-C2'))->toContain('Campus II')->toContain('Solo lectura')->toContain('Abre')
            ->and(packagesPageText($page, 'intake-C2'))->toBe('64')
            ->and(packagesPageText($page, 'packages-suggested-C2'))->toBe('2')
            ->and(packagesPageText($page, 'packages-status-C2'))->toBe('Alcanza: 70 lugares');
    });

    it('lista cada paquete con sus materias como fichas y la cuenta de cada campus; la de otro campus como texto (M7)', function () {
        $package = packagesPagePackage([$this->calculus, $this->programming, $this->integral]);

        $page = packagesPageAs($this->ofC1);
        $row = domOf($page)->querySelector('[data-testid="package-P1"]');

        expect(textOf($row))->toContain('Paquete 1')->toContain('3 materias')->toContain('ACF-0901')->toContain('SCD-1008')->toContain('ACF-0902')
            ->and($row->querySelector('[data-testid="package-chip-ACF-0902"]')->textContent)->toContain('2.º')
            ->and($row->querySelector('input[data-testid="package-count-P1-C1"]')?->getAttribute('value'))->toBe('5')
            ->and($row->querySelector('input[data-testid="package-count-P1-C2"]'))->toBeNull()
            ->and(textOf($row->querySelector('[data-testid="package-count-P1-C2"]')))->toContain('2');

        expect($package->fresh()->code)->toBe('P1');
    });

    it('se ve también en la retícula', function () {
        packagesPagePackage([$this->calculus]);

        packagesPageAs($this->ofC1, ['vista' => 'reticula'])
            ->assertSeeHtml('data-testid="packages"')
            ->assertSeeHtml('data-testid="package-P1"');
    });

    it('si faltan lugares lo dice con cuántos', function () {
        $package = packagesPagePackage([$this->calculus]);
        ProposalPackageCampus::query()->where('proposal_package_id', $package->id)->where('campus_id', $this->c1->id)->update(['count' => 4, 'captured_at' => now()]);

        expect(packagesPageText(packagesPageAs($this->ofC1), 'packages-status-C1'))->toBe('Faltan 31 lugares');
    });

    it('en la fila de una materia con paquetes dice cuántos de sus grupos salen de paquetes', function () {
        packagesPagePackage([$this->calculus]);

        $page = packagesPageAs($this->ofC1);
        $cell = domOf($page)->querySelector('[data-testid="row-ACF-0901"] [data-cell="'.packagesPageItem($this->calculus, $this->c1)->id.'.requested_groups"]');

        expect($cell->querySelector('input')->getAttribute('value'))->toBe('6')
            ->and(textOf($cell->querySelector('[data-package-groups]')))->toBe('5 de paq.')
            ->and(domOf($page)->querySelector('[data-testid="row-ACF-0902"] [data-package-groups]'))->toBeNull();
    });

    it('«Usar sugerido» del panel, con paquetes, pide los de paquetes más los sueltos sugeridos', function () {
        packagesPagePackage([$this->calculus]);
        $item = packagesPageItem($this->calculus, $this->c1);

        $page = packagesPageAs($this->ofC1)
            ->call('edit', $this->calculus->id)
            ->assertSee('Usar sugerido: 6 (35 por grupo)')
            ->call('useSuggested', $item->id);

        expect($page->get("form.{$item->id}.requested_groups"))->toBe(6);
    });

    it('el panel de la materia dice cuántos grupos salen de paquetes y cuántos son sueltos', function () {
        packagesPagePackage([$this->calculus]);

        $page = packagesPageAs($this->ofC1)->call('edit', $this->calculus->id);

        expect(packagesPageText($page, 'panel-packages-C1'))->toBe('5 de paquetes + 1 suelto')
            ->and(packagesPageText($page, 'panel-packages-C2'))->toBe('2 de paquetes + 1 suelto');
    });
});

describe('recién abierta', function () {
    it('nace sin paquetes: lo avisa y ofrece armar uno con las materias de 1.º', function () {
        $page = packagesPageAs($this->ofC1)
            ->assertSeeHtml('data-testid="packages-empty"')
            ->assertSee('Todavía no hay paquetes.')
            ->assertSee('Armar paquete con las materias de 1.º');

        expect(packagesPageText($page, 'packages-status-C1'))->toBe('Sin paquetes: el nuevo ingreso no tiene grupos')
            ->and(packagesPageText($page, 'packages-open-C1'))->toBe('0');

        $page->call('newPackage', true)
            ->assertSet('showPackage', true)
            ->assertSet('packageId', null);

        expect($page->get('packageSubjects'))->toEqualCanonicalizing([$this->calculus->id, $this->programming->id]);
    });
});

describe('partes aceptadas', function () {
    it('«Armar paquete con las materias de 1.º» deja fuera las de un departamento que ya aceptó su parte; el panel las muestra sin casilla activa y con el motivo', function () {
        ProposalReview::factory()->create(['career_proposal_id' => $this->proposal->id, 'department_id' => $this->sc->id, 'status' => ProposalReviewStatus::Accepted]);

        $page = packagesPageAs($this->ofC1)->call('newPackage', true);

        expect($page->get('packageSubjects'))->toBe([$this->calculus->id]);

        $checkbox = domOf($page)->querySelector('[data-testid="package-subject-SCD-1008"]');

        expect($checkbox->hasAttribute('disabled'))->toBeTrue()
            ->and(packagesPageText($page, 'package-subject-lock-SCD-1008'))->toBe('SC ya aceptó su parte')
            ->and(domOf($page)->querySelector('[data-testid="package-subject-ACF-0901"]')->hasAttribute('disabled'))->toBeFalse();

        $page->call('savePackage')->assertHasNoErrors();

        expect(ProposalPackage::query()->sole()->subjects()->pluck('subject_id')->all())->toBe([$this->calculus->id]);
    });
});

describe('acciones desde la página', function () {
    it('arma un paquete con las materias elegidas y su nombre', function () {
        $page = packagesPageAs($this->ofC1)
            ->call('newPackage')
            ->assertSet('packageSubjects', [])
            ->set('packageName', 'Matutino')
            ->set('packageSubjects', [$this->calculus->id, $this->integral->id])
            ->call('savePackage')
            ->assertHasNoErrors()
            ->assertSet('showPackage', false);

        $package = ProposalPackage::query()->where('career_proposal_id', $this->proposal->id)->sole();

        expect($package->name)->toBe('Matutino')
            ->and($package->subjects()->pluck('subject_id')->all())->toEqualCanonicalizing([$this->calculus->id, $this->integral->id])
            ->and(lastToastOf($page))->toMatchArray(['type' => 'success', 'title' => 'Paquete armado'])
            ->and(packagesPageItem($this->calculus, $this->c1)->requested_groups)->toBe(6);
    });

    it('sin materias no lo arma y dice por qué junto a las casillas', function () {
        packagesPageAs($this->ofC1)
            ->call('newPackage')
            ->call('savePackage')
            ->assertHasErrors(['packageSubjects'])
            ->assertSee('Elige al menos una materia para el paquete.')
            ->assertSet('showPackage', true);

        expect(ProposalPackage::query()->count())->toBe(0);
    });

    it('cambia las materias y el nombre de un paquete', function () {
        $package = packagesPagePackage([$this->calculus]);

        $page = packagesPageAs($this->ofC1)
            ->call('editPackage', $package->id)
            ->assertSet('packageId', $package->id)
            ->assertSet('packageSubjects', [$this->calculus->id])
            ->set('packageName', 'Vespertino')
            ->set('packageSubjects', [$this->calculus->id, $this->programming->id])
            ->call('savePackage')
            ->assertHasNoErrors();

        expect($package->fresh()->name)->toBe('Vespertino')
            ->and($package->subjects()->pluck('subject_id')->all())->toEqualCanonicalizing([$this->calculus->id, $this->programming->id])
            ->and(packagesPageItem($this->programming, $this->c1)->requested_groups)->toBe(6)
            ->and(lastToastOf($page))->toMatchArray(['type' => 'success', 'title' => 'Paquete guardado']);
    });

    it('quita un paquete y sus materias dejan de abrir grupos por él', function () {
        $package = packagesPagePackage([$this->calculus]);

        $page = packagesPageAs($this->ofC1)
            ->call('editPackage', $package->id)
            ->call('deletePackage')
            ->assertSet('showPackage', false);

        expect(ProposalPackage::query()->count())->toBe(0)
            ->and(packagesPageItem($this->calculus, $this->c1)->requested_groups)->toBe(1)
            ->and(lastToastOf($page))->toMatchArray(['type' => 'success', 'title' => 'Paquete quitado']);
    });

    it('«Quitar paquete» pide confirmación con el diálogo de Nexus, nunca uno del navegador', function () {
        $package = packagesPagePackage([$this->calculus]);

        $button = domOf(packagesPageAs($this->ofC1)->call('editPackage', $package->id))->querySelector('[data-testid="package-delete"]');

        expect($button->getAttribute('x-confirm'))->not->toBe('')
            ->and($button->getAttribute('data-confirm-title'))->toBe('¿Quitar Paquete 1?')
            ->and($button->getAttribute('data-confirm-tone'))->toBe('error')
            ->and($button->hasAttribute('wire:confirm'))->toBeFalse();
    });

    it('captura cuántos abre su campus y los grupos de la fila suman los de los paquetes', function () {
        $package = packagesPagePackage([$this->calculus]);

        packagesPageAs($this->ofC1)
            ->call('savePackageCount', $package->id, $this->c1->id, '2')
            ->assertDispatched('cell-saved');

        expect(packagesPageCount($package, $this->c1))->toBe(2)
            ->and(packagesPageItem($this->calculus, $this->c1)->only(['package_groups', 'requested_groups']))->toBe(['package_groups' => 2, 'requested_groups' => 3]);
    });

    it('corrige el nuevo ingreso de su campus: queda marcado y los paquetes sin tocar lo siguen', function () {
        $package = packagesPagePackage([$this->calculus]);

        $page = packagesPageAs($this->ofC1)->call('saveIntake', $this->c1->id, '100');

        $intake = ProposalIntake::query()->where('career_proposal_id', $this->proposal->id)->where('campus_id', $this->c1->id)->sole();

        expect($intake->new_students)->toBe(100)
            ->and($intake->captured_at)->not->toBeNull()
            ->and(packagesPageCount($package, $this->c1))->toBe(3)
            ->and(packagesPageText($page, 'intake-source-C1'))->toBe('Corregido · Nexus 171')
            ->and(packagesPageText($page, 'packages-suggested-C1'))->toBe('3');
    });

    it('un número que no es entero no se guarda y lo dice en la celda', function () {
        $package = packagesPagePackage([$this->calculus]);

        $page = packagesPageAs($this->ofC1)->call('savePackageCount', $package->id, $this->c1->id, 'dos');

        expect(packagesPageCount($package, $this->c1))->toBe(5)
            ->and(lastToastOf($page))->toMatchArray(['type' => 'error', 'description' => 'Escribe un número entero.'])
            ->and(packagesPageText($page, 'package-count-error-P1-C1'))->toBe('Escribe un número entero.');
    });

    it('el tope de paquetes lo dice la Action, en un toast y en la celda', function () {
        $package = packagesPagePackage([$this->calculus]);

        $page = packagesPageAs($this->ofC1)->call('savePackageCount', $package->id, $this->c1->id, '31');

        expect(packagesPageCount($package, $this->c1))->toBe(5)
            ->and(lastToastOf($page))->toMatchArray(['type' => 'error', 'description' => 'Los paquetes que se abren van de 0 a 30.'])
            ->and(packagesPageText($page, 'package-count-error-P1-C1'))->toBe('Los paquetes que se abren van de 0 a 30.');
    });

    it('con la parte de un departamento aceptada, el motivo de la Action va en el toast', function () {
        $package = packagesPagePackage([$this->calculus]);
        ProposalReview::factory()->create(['career_proposal_id' => $this->proposal->id, 'department_id' => $this->cb->id, 'status' => ProposalReviewStatus::Accepted]);

        $page = packagesPageAs($this->ofC1)->call('savePackageCount', $package->id, $this->c1->id, '2');

        expect(packagesPageCount($package, $this->c1))->toBe(5)
            ->and(lastToastOf($page))->toMatchArray(['type' => 'error', 'description' => 'CB ya aceptó su parte: sus materias ya no cambian.']);
    });
});

describe('alcance (M7)', function () {
    it('la columna de otro campus es de solo lectura: su nuevo ingreso y sus cuentas como texto, con quién la captura', function () {
        packagesPagePackage([$this->calculus]);

        $dom = domOf(packagesPageAs($this->ofC1));

        expect($dom->querySelector('input[data-testid="intake-C1"]'))->not->toBeNull()
            ->and($dom->querySelector('input[data-testid="intake-C2"]'))->toBeNull()
            ->and($dom->querySelector('input[data-testid="package-count-P1-C2"]'))->toBeNull()
            ->and(textOf($dom->querySelector('[data-testid="packages-column-C2"]')))->toContain('Daniela Quintero');
    });

    it('el coordinador de un campus no captura la cuenta ni el nuevo ingreso de otro: 403 y nada cambia', function () {
        $package = packagesPagePackage([$this->calculus]);

        packagesPageAs($this->ofC1)->call('savePackageCount', $package->id, $this->c2->id, '1')->assertForbidden();
        packagesPageAs($this->ofC1)->call('saveIntake', $this->c2->id, '10')->assertForbidden();

        expect(packagesPageCount($package, $this->c2))->toBe(2)
            ->and(ProposalIntake::query()->where('campus_id', $this->c2->id)->sole()->new_students)->toBe(64);
    });

    it('la composición es de la carrera: el coordinador de C2 también arma paquetes', function () {
        packagesPageAs($this->ofC2)
            ->call('newPackage')
            ->set('packageSubjects', [$this->calculus->id])
            ->call('savePackage')
            ->assertHasNoErrors();

        expect(ProposalPackage::query()->count())->toBe(1);
    });

    it('la subdirección lo ve de solo lectura y no puede armar, cambiar, quitar ni capturar', function () {
        $package = packagesPagePackage([$this->calculus]);

        $page = packagesPageAs($this->subdirection)
            ->assertSeeHtml('data-testid="packages"')
            ->assertDontSeeHtml('data-testid="packages-add"');

        expect(domOf($page)->querySelector('[data-testid="packages"] input'))->toBeNull();

        packagesPageAs($this->subdirection)->call('newPackage')->assertForbidden();
        packagesPageAs($this->subdirection)->call('editPackage', $package->id)->assertSet('showPackage', true)->call('savePackage')->assertForbidden();
        packagesPageAs($this->subdirection)->call('editPackage', $package->id)->call('deletePackage')->assertForbidden();
        packagesPageAs($this->subdirection)->call('savePackageCount', $package->id, $this->c1->id, '1')->assertForbidden();
        packagesPageAs($this->subdirection)->call('saveIntake', $this->c1->id, '10')->assertForbidden();

        expect(ProposalPackage::query()->count())->toBe(1)->and(packagesPageCount($package, $this->c1))->toBe(5);
    });

    it('un paquete de otra propuesta no existe para la página', function () {
        $other = ProposalPackage::factory()->create();

        packagesPageAs($this->ofC1)->call('editPackage', $other->id)->assertNotFound();
    });
});

describe('enviada', function () {
    it('queda de solo lectura: sin agregar, sin campos y el panel solo para ver', function () {
        $package = packagesPagePackage([$this->calculus]);
        $this->proposal->update(['status' => ProposalStatus::Submitted, 'submitted_at' => now(), 'submitted_by' => $this->ofC1->id]);

        $page = packagesPageAs($this->ofC1)
            ->assertDontSeeHtml('data-testid="packages-add"');

        expect(domOf($page)->querySelector('[data-testid="packages"] input'))->toBeNull()
            ->and(domOf($page)->querySelector('[data-testid="package-edit-P1"]')->getAttribute('aria-label'))->toBe('Ver Paquete 1');

        $page->call('editPackage', $package->id)->assertSet('showPackage', true)->assertDontSeeHtml('data-testid="package-save"')->assertDontSeeHtml('data-testid="package-delete"');
    });

    it('si una acción llega de todos modos, la Action la rechaza con su motivo y nada cambia', function () {
        $package = packagesPagePackage([$this->calculus]);
        $this->proposal->update(['status' => ProposalStatus::Submitted, 'submitted_at' => now(), 'submitted_by' => $this->ofC1->id]);

        $page = packagesPageAs($this->ofC1)->call('savePackageCount', $package->id, $this->c1->id, '1');
        expect(lastToastOf($page))->toMatchArray(['type' => 'error', 'description' => 'La propuesta ya se envió: solo se cambia si un departamento la regresa.']);

        $page = packagesPageAs($this->ofC1)->call('saveIntake', $this->c1->id, '10');
        expect(lastToastOf($page)['type'])->toBe('error');

        $page = packagesPageAs($this->ofC1)->call('editPackage', $package->id)->call('deletePackage');
        expect(lastToastOf($page)['type'])->toBe('error');

        expect(ProposalPackage::query()->count())->toBe(1)
            ->and(packagesPageCount($package, $this->c1))->toBe(5)
            ->and(ProposalIntake::query()->where('campus_id', $this->c1->id)->sole()->new_students)->toBe(171);
    });
});
