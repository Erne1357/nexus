<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;

/*
| Las cuentas del personal en el navegador (1.22-C1 y C2): el admin da de alta a una persona con su correo
| institucional desde «Personas y puestos» y llega a su ficha con la invitación enviada (CF-S3, CF-07); la nombra jefa
| de un departamento; la da de baja en el cajón, con su motivo y el aviso del puesto que queda sin titular (CF-05), y la
| reactiva con el diálogo que dice a qué correo va la invitación (CF-06). Las reglas de cada paso se prueban en
| tests/Feature/Core; aquí, lo que agrega el navegador: el cajón, el menú «Acciones» y el diálogo de confirmar.
*/

beforeEach(function () {
    Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->systems = Department::factory()->create(['name' => 'Sistemas y Computación']);

    User::factory()->admin()->create(['username' => 'admin', 'name' => 'Ana Admin']);
    $this->email = 'marisol.quintero@'.config('nexus.institutional_email_domain');
});

function signInToAccounts()
{
    return visit('/login')
        ->fill('username', 'admin')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->assertScript('window.Alpine?.$data(document.body).menuOpen === false');
}

it('CF-S3, CF-07, CF-05 y CF-06: el admin da de alta, nombra, da de baja y reactiva una cuenta', function () {
    $page = signInToAccounts()
        ->click('@hare')
        ->click('#nexus-menu a[data-label="Personas y puestos"]')
        ->assertPathIs('/people')
        ->click('@create-user')
        ->assertSeeIn('@create-user-drawer', 'Nace sin acceso; después lo nombras en su ficha.')
        ->fill('newUsername', 'QUMM800101AB1')
        ->fill('newName', 'Marisol Quintero Medina')
        ->fill('newEmail', $this->email)
        ->click('@create-user-submit')
        ->assertSee('Invitación enviada a '.$this->email)
        ->assertPathBeginsWith('/people/')
        ->assertSee('Marisol Quintero Medina')
        ->assertSeeIn('@user-access', 'Invitación enviada')
        ->assertSee('Sin puesto vigente')
        ->assertNoJavaScriptErrors();

    $marisol = User::query()->where('username', 'QUMM800101AB1')->sole();

    expect($marisol->password)->toBeNull()
        ->and($marisol->access_invited_at)->not->toBeNull();

    $page->click('@appoint')
        ->assertSee('Nombrar a Marisol Quintero Medina')
        ->select('role', 'jefe_departamento')
        ->select('unit', $this->systems->id)
        ->fill('from', today()->subDays(10)->toDateString())
        ->click('@appoint-submit')
        ->assertSee('Nombramiento creado')
        ->assertSee('Con 1 puesto vigente')
        ->assertNoJavaScriptErrors();

    $page->click('[aria-label="Acciones de Marisol Quintero Medina"]')
        ->click('[role="menuitem"]:has-text("Dar de baja")')
        ->assertSeeIn('@deactivate-drawer', 'Dar de baja a Marisol Quintero Medina')
        ->assertSeeIn('@deactivate-assignments', 'Termina hoy')
        ->assertSeeIn('@deactivate-assignments', 'Sin titular desde mañana')
        ->click('@deactivate-submit')
        ->assertSeeIn('@deactivate-drawer', 'Elige el motivo.')
        ->select('deactivationReason', 'cambio_de_area')
        ->fill('deactivationNote', 'Pasa a la Subdirección de Planeación.')
        ->click('@deactivate-submit')
        ->assertSee('Cuenta dada de baja')
        ->assertSeeIn('@account-status', 'Dada de baja')
        ->assertSeeIn('@user-access', 'Sin acceso')
        ->assertSeeIn('@assignments', 'Termina hoy')
        ->assertNoJavaScriptErrors();

    $marisol->refresh();

    expect($marisol->is_active)->toBeFalse()
        ->and($marisol->deactivated_at)->not->toBeNull()
        ->and(RoleAssignment::query()->where('user_id', $marisol->id)->sole())
        ->role->toBe(Role::JefeDepartamento)
        ->valid_to->toDateString()->toBe(today()->toDateString());

    $page->click('[aria-label="Acciones de Marisol Quintero Medina"]')
        ->click('[role="menuitem"]:has-text("Reactivar")')
        ->assertSeeIn('#nexus-confirm', '¿Reactivar la cuenta de QUMM800101AB1?')
        ->assertSeeIn('#nexus-confirm', 'Le llega una invitación a '.$this->email.'.')
        ->click('@confirm-accept')
        ->assertSee('Cuenta reactivada')
        ->assertSeeIn('@account-status', 'Activa')
        ->assertSeeIn('@user-access', 'Invitación enviada')
        ->assertNoJavaScriptErrors();

    expect($marisol->fresh())
        ->is_active->toBeTrue()
        ->password->toBeNull()
        ->and(RoleAssignment::query()->where('user_id', $marisol->id)->count())->toBe(1);
});
