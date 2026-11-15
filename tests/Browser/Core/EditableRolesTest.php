<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;

/*
| Roles editables en el navegador (1.22-A y B, CF-01): el admin le da a «Coordinador de carrera» el permiso suelto «Ver
| periodos» en el cajón de «Puestos y permisos» y un coordinador, que ya tenía su sesión abierta, ve «Periodos» en su
| menú en la siguiente página; el admin se lo quita con el diálogo de confirmar y el coordinador deja de verlo. Las
| reglas (candados, alcance, bitácora) se prueban en tests/Feature/Core/PermissionsPageTest.php; aquí, lo que agrega
| el navegador: el cajón, el aviso de que valdría en todo el Tec, el diálogo y el menú de otra sesión.
|
| Las dos sesiones van abiertas a la vez, cada una en su contexto del navegador. El servidor de las pruebas atiende
| todas las peticiones con la misma aplicación, así que al terminar cada una se olvidan los guards (el usuario de la
| petición anterior) y las instancias `scoped` (Actor y GrantTable), como en Octane.
*/

beforeEach(function () {
    Event::listen(RequestHandled::class, function (): void {
        Auth::forgetGuards();
        app()->forgetScopedInstances();
    });

    $c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $isc = Career::factory()->create(['code' => 'ISC', 'name' => 'Ingeniería en Sistemas Computacionales']);
    CareerCampus::factory()->create(['career_id' => $isc->id, 'campus_id' => $c2->id]);

    User::factory()->admin()->create(['username' => 'admin', 'name' => 'Ana Admin']);
    User::factory()
        ->withRole(Role::Coordinador, ['campus_id' => $c2->id, 'career_id' => $isc->id])
        ->create(['username' => '19110001', 'name' => 'Carlos Coordinador']);
});

function signInToRoles(string $username)
{
    return visit('/login')
        ->fill('username', $username)
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->assertScript('window.Alpine?.$data(document.body).menuOpen === false');
}

const TERMS_ENTRY = '#nexus-menu a[data-label="Periodos"]';

it('CF-01: el permiso suelto que da el admin aparece en el menú del coordinador en su siguiente página, y se va al quitarlo', function () {
    $coordinator = signInToRoles('19110001')
        ->assertScript('document.querySelector(\''.TERMS_ENTRY.'\') === null');

    $admin = signInToRoles('admin')
        ->click('@hare')
        ->click('#nexus-menu a[data-label="Puestos y permisos"]')
        ->assertPathIs('/configuracion/puestos-y-permisos')
        ->click('[data-testid="position-card"][data-position="coordinador"]')
        ->assertSeeIn('#position-title', 'Coordinador de carrera')
        ->click('@add-loose')
        ->assertSeeIn('@add-loose-panel', 'Agregar permisos sueltos')
        ->fill('[aria-label="Buscar permiso"]', 'Ver periodos')
        ->assertSeeIn('[data-testid="loose-row-core.term.view"]', 'Valdrá en todo el Tec')
        ->click('[data-testid="loose-row-core.term.view"] input[type="checkbox"]')
        ->assertSeeIn('@add-loose-submit', 'Dar 1 permiso')
        ->click('@add-loose-submit')
        ->assertSee('1 permiso suelto para Coordinador de carrera')
        ->assertSeeIn('@position-loose', 'Ver periodos')
        ->assertNoJavaScriptErrors();

    $coordinator->navigate('/')
        ->assertScript('document.querySelector(\''.TERMS_ENTRY.'\') !== null')
        ->click('@hare')
        ->click(TERMS_ENTRY)
        ->assertPathIs('/terms')
        ->assertNoJavaScriptErrors();

    $admin->click('[aria-label="Quitar Ver periodos"]')
        ->assertSeeIn('#nexus-confirm', '¿Quitar «Ver periodos»?')
        ->click('@confirm-accept')
        ->assertSee('Permiso suelto quitado')
        ->assertSee('Sin permisos sueltos.')
        ->assertNoJavaScriptErrors();

    $coordinator->navigate('/')
        ->assertScript('document.querySelector(\''.TERMS_ENTRY.'\') === null')
        ->assertNoJavaScriptErrors();
});
