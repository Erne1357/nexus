<?php

use App\Modules\Core\Actions\SetTermStage;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\TermStage;
use App\Modules\Core\Models\User;

/*
| Catálogo de Periodos en el navegador: el admin da de alta un periodo, lo edita y lo marca como
| actual desde la UI; un coordinador no ve la opción en el menú.
*/

beforeEach(function () {
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);

    User::factory()->admin()->create(['username' => 'admin', 'name' => 'Ana Admin']);
    User::factory()
        ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])
        ->create(['username' => '19110001', 'name' => 'Carlos Coordinador']);
});

function signInToTerms(string $username)
{
    return visit('/login')
        ->fill('username', $username)
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->assertScript('window.Alpine?.$data(document.body).menuOpen === false');
}

it('el admin da de alta un periodo, lo edita y lo marca como actual desde la UI', function () {
    $page = signInToTerms('admin')
        ->click('#nexus-menu a[data-label="Periodos"]')
        ->assertPathIs('/terms')
        ->press('Nuevo periodo')
        ->fill('code', '20271')
        ->fill('starts_on', '2027-01-18')
        ->fill('ends_on', '2027-06-11')
        ->wait(1)
        ->assertValue('name', 'Ene-Jun 2027')
        ->press('Guardar')
        ->waitForText('Periodo creado')
        ->assertSee('Ene-Jun 2027')
        ->assertNoJavaScriptErrors();

    expect(Term::where('code', '20271')->sole()->is_current)->toBeFalse();

    $page->click('@edit-20271')
        ->waitForText('Editar periodo')
        ->assertValue('code', '20271')
        ->fill('name', 'Enero a Junio 2027')
        ->press('Guardar')
        ->waitForText('Periodo actualizado')
        ->assertSee('Enero a Junio 2027')
        ->assertNoJavaScriptErrors();

    expect(Term::where('code', '20271')->sole()->name)->toBe('Enero a Junio 2027');

    $page->click('table [aria-label="Acciones de 20271"]')
        ->click('@make-current-20271')
        ->click('@confirm-accept')
        ->wait(1)
        ->assertPresent('table .badge-primary')
        ->assertNoJavaScriptErrors();

    expect(Term::where('code', '20271')->sole()->is_current)->toBeTrue();
});

it('el admin abre la ficha de un periodo, fija las fechas de una etapa y la ventana propia de Campus II y ve las barras', function () {
    $term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);

    signInToTerms('admin')
        ->navigate('/terms/'.$term->id)
        ->assertSee('Ene-Jun 2027')
        ->assertSee('Calendario')
        ->click('@stage-scheduling')
        ->waitForText('Etapa 3 de 8')
        ->fill('@general-starts', '2027-01-04')
        ->fill('@general-ends', '2027-01-22')
        ->click('@add-C2')
        ->wait(1)
        ->fill('@own-C2-starts', '2027-01-11')
        ->fill('@own-C2-ends', '2027-01-29')
        ->click('@save-stage')
        ->waitForText('Etapa actualizada')
        ->assertSee('4–22 ene')
        ->assertPresent('[data-testid="stage-scheduling"] i.bg-primary')
        ->assertNoJavaScriptErrors();

    expect(TermStage::where('term_id', $term->id)->count())->toBe(2);
});

it('un coordinador no ve la opción Periodos en el menú', function () {
    signInToTerms('19110001')
        ->assertSeeIn('[aria-label="Navegación principal"]', 'Inicio')
        ->assertDontSeeIn('[aria-label="Navegación principal"]', 'Periodos')
        ->click('@hare')
        ->assertDontSeeIn('[aria-label="Navegación principal"]', 'Periodos')
        ->assertNoJavaScriptErrors();
});

it('el admin abre el periodo en Campus II desde la ficha y ve quién y cuándo', function () {
    $term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);

    $page = signInToTerms('admin')
        ->navigate('/terms/'.$term->id)
        ->assertSee('No abierto en este periodo');

    $page->click('@open-C2')
        ->click('@confirm-accept')
        ->waitForText('Periodo abierto en C2')
        ->waitForText('Abierto el')
        ->assertSee('por Ana Admin')
        ->assertNoJavaScriptErrors();

    expect(TermOpening::where('term_id', $term->id)->count())->toBe(1);
});

it('el admin copia el calendario de otro periodo a un periodo vacío y ve las barras', function () {
    $source = Term::factory()->create(['code' => '20263', 'name' => 'Ago-Dic 2026', 'starts_on' => '2026-08-17', 'ends_on' => '2026-12-11']);
    $target = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-25', 'ends_on' => '2027-06-11']);
    app(SetTermStage::class)->handle($source, Stage::CatalogValidation, null, '2026-08-17', '2026-08-28');
    app(SetTermStage::class)->handle($source, Stage::Proposal, null, '2026-08-24', '2026-09-04');

    signInToTerms('admin')
        ->navigate('/terms/'.$target->id)
        ->assertSee('Este periodo no tiene calendario.')
        ->click('@copy-calendar')
        ->waitForText('Copiar el calendario de otro periodo')
        ->select('@copy-source', $source->id)
        ->waitForText('Fechas corridas 23 semanas hacia adelante.')
        ->assertSee('25 ene – 5 feb')
        ->click('@copy-confirm')
        ->waitForText('2 ventanas copiadas de 20263')
        ->assertPresent('[data-testid="stage-catalog_validation"] i')
        ->assertNoJavaScriptErrors();

    expect(TermStage::where('term_id', $target->id)->count())->toBe(2);
});
