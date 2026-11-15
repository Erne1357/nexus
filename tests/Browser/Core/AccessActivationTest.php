<?php

use App\Modules\Core\Actions\SendAccessInvitation;
use App\Modules\Core\Actions\SetTermStage;
use App\Modules\Core\Enums\PasswordOrigin;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\AccessInvitation;
use Illuminate\Support\Facades\Notification;

/*
| El flujo del docente (AC-07, AC-08 y D12, confirmados el 14 de noviembre de 2026): recibe la invitación sin
| contraseña, abre el enlace, elige la suya en «Activar mi acceso» sin que se le pida la actual, entra a su Inicio sin
| el calendario del periodo y con su acceso, y después entra con esa contraseña.
*/

it('AC-07, AC-08 e IN-01: el docente activa su acceso con su propia contraseña y llega a su Inicio', function () {
    $this->travelTo('2026-11-14 10:00:00');
    $term = Term::factory()->current()->create(['name' => 'Ene-Jun 2027']);
    app(SetTermStage::class)->handle($term, Stage::CatalogValidation, null, '2026-11-16', '2026-11-27');
    $teacher = User::factory()->withRole(Role::Docente)->create(['username' => 'PEAA800101XX1', 'name' => 'Ana Pérez', 'email' => null, 'password' => null]);

    // El enlace se arma después de levantar el servidor de la prueba, para que su firma sea la de esa dirección.
    $page = visit('/login')->assertSee('¿Olvidaste tu contraseña?');

    Notification::fake();
    app(SendAccessInvitation::class)->handle($teacher, 'ana.perez@cdjuarez.tecnm.mx');
    $link = null;
    Notification::assertSentOnDemand(AccessInvitation::class, function (AccessInvitation $invitation) use (&$link): bool {
        $link = $invitation->url;

        return true;
    });

    $page->navigate($link)
        ->assertSee('Activa tu acceso')
        ->assertSee('PEAA800101XX1')
        ->assertDontSee('Contraseña actual')
        ->fill('password', 'corta1')
        ->fill('password_confirmation', 'corta1')
        ->press('Activar mi acceso')
        ->assertSee('Activa tu acceso')
        ->assertSee('8 caracteres')
        ->fill('password', 'Propia-clave-2027')
        ->fill('password_confirmation', 'Propia-clave-2027')
        ->press('Activar mi acceso')
        ->assertPathIs('/')
        ->assertSee('Hola, Ana Pérez')
        ->assertSee('Tu acceso')
        ->assertSee('ana.perez@cdjuarez.tecnm.mx')
        ->assertDontSee('Calendario')
        ->assertDontSee('Validación de catálogos')
        ->assertNoJavaScriptErrors();

    expect($teacher->fresh())
        ->password_origin->toBe(PasswordOrigin::User)
        ->must_change_password->toBeFalse()
        ->email_verified_at->not->toBeNull();

    $page->click('@credential')
        ->press('Cerrar sesión')
        ->assertPathIs('/login')
        ->fill('username', 'PEAA800101XX1')
        ->fill('password', 'Propia-clave-2027')
        ->press('Entrar')
        ->assertPathIs('/')
        ->assertSee('Hola, Ana Pérez')
        ->assertNoJavaScriptErrors();
});
