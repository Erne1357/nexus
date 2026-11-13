<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;

/*
| Modalidades en el navegador (tarea 14, U4 opción A): el admin la abre desde el menú junto a Campus, edita el perfil de
| Presencial en el panel lateral y no desborda a 390 px; con capturas en cinco anchos y en oscuro para la revisión visual.
*/

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    foreach (['CB', 'CEA', 'EE', 'II', 'MM', 'SC'] as $code) {
        Department::factory()->create(['code' => $code]);
    }
    $this->term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-25', 'ends_on' => '2027-06-11']);

    User::factory()->admin()->create(['username' => 'admin', 'name' => 'Ana Admin']);
});

function signInToModalities()
{
    return visit('/login')
        ->fill('username', 'admin')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/');
}

it('el admin abre Modalidades desde el menú y edita el perfil de Presencial en el panel', function () {
    $page = signInToModalities()
        ->navigate('/modalidades')
        ->assertSee('Modalidades')
        ->assertSee('Presencial')
        ->click('@edit-PRES')
        ->waitForText('Así se resume')
        ->assertSee('Salones · bloques de 60 min · lunes a viernes, 7:00 a 21:00')
        ->fill('@day-end', '22:00')
        ->click('@save-modality')
        ->waitForText('Modalidad actualizada')
        ->assertSee('7:00 a 22:00')
        ->assertNoJavaScriptErrors();

    expect(Campus::where('code', 'C1')->sole()->profile->dayEnd)->toBe('22:00');
});

it('Modalidades no desborda a 390 px, ni la tabla ni el panel', function () {
    signInToModalities()
        ->resize(390, 844)
        ->navigate('/modalidades')
        ->assertSee('Presencial')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->click('[data-testid="modality-cards"] [aria-label="Editar PRES"]')
        ->waitForText('Así se resume')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoJavaScriptErrors();
});

it('capturas de Modalidades, el panel de perfil, Campus y «Abrir el periodo» en cinco anchos', function () {
    $page = signInToModalities();

    foreach ([390, 768, 1280, 1920, 2560] as $width) {
        $page->resize($width, 1000)->navigate('/modalidades')->assertSee('Presencial')->screenshot(filename: "modalidades-{$width}");
        $page->click($width < 768 ? '[data-testid="modality-cards"] [aria-label="Editar PRES"]' : '@edit-PRES')->waitForText('Así se resume')->screenshot(filename: "modalidades-perfil-{$width}");
        $page->press('Cancelar');
    }

    $page->resize(1280, 1000)->navigate('/campus')->assertSee('Campus II')->screenshot(filename: 'campus-1280');
    $page->resize(390, 844)->navigate('/campus')->assertSee('Campus II')->screenshot(filename: 'campus-390');

    foreach ([390, 768, 1280, 1920, 2560] as $width) {
        $page->resize($width, 1000)->navigate('/terms/'.$this->term->id)->click('@open-PRES')->waitForText('Revisa lo que se crea antes de abrir.')->screenshot(filename: "abrir-periodo-{$width}");
    }

    $page->resize(1280, 1000)->navigate('/terms/'.$this->term->id)->click('@stage-scheduling')->waitForText('Etapa 3 de 8')->screenshot(filename: 'etapa-1280');
    $page->resize(390, 844)->navigate('/terms/'.$this->term->id)->click('@stage-scheduling')->waitForText('Etapa 3 de 8')->screenshot(filename: 'etapa-390');
});

it('capturas en oscuro de Modalidades y «Abrir el periodo»', function () {
    $page = signInToModalities()->resize(1280, 1000)->navigate('/modalidades');
    $page->script("document.documentElement.dataset.theme = 'nexus-dark'");

    $page->assertSee('Presencial')->screenshot(filename: 'modalidades-oscuro-1280')
        ->click('@edit-PRES')->waitForText('Así se resume')->screenshot(filename: 'modalidades-perfil-oscuro-1280')
        ->press('Cancelar')
        ->navigate('/terms/'.$this->term->id);
    $page->script("document.documentElement.dataset.theme = 'nexus-dark'");

    $page->click('@open-PRES')->waitForText('Revisa lo que se crea antes de abrir.')->screenshot(filename: 'abrir-periodo-oscuro-1280')
        ->assertNoJavaScriptErrors();
});
