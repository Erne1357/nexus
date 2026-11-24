<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Staffing\Enums\SelectionEntryStatus;
use Carbon\CarbonImmutable;
use Tests\Support\Staffing\SelectionWorld;

/*
| Flujo de la orden de selección en el navegador (tarea 1.33): con la selección publicada, el docente en turno abre la
| oferta, toma un grupo con su salón desde la pestaña Lista y termina su carga; el docente siguiente ve el grupo tomado.
| Nombres ficticios.
*/

it('el docente en su turno toma grupo y salón y termina; el siguiente ve el grupo tomado', function () {
    $world = SelectionWorld::create();
    $uno = $world->teacher(base: 5, name: 'Docente Uno');
    $dos = $world->teacher(base: 5, slotStartsAt: CarbonImmutable::now()->addMinutes(25), position: 2, name: 'Docente Dos');
    foreach ([$uno, $dos] as $teacher) {
        RoleAssignment::factory()->for($teacher->user)->create(['role' => Role::Docente]);
    }
    $uno->user->update(['username' => 'docente.uno']);
    $dos->user->update(['username' => 'docente.dos']);
    $group = $world->group('A', [[1, '09:00', '10:00'], [3, '09:00', '10:00']]);
    $room = $world->room('A1');

    visit('/login')
        ->fill('username', 'docente.uno')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/mi-espacio/oferta')
        ->assertSee('Es tu turno')
        ->click('[data-testid="selection-card-'.$group->id.'"]')
        ->waitFor('[data-testid="selection-rooms"]')
        ->click('input[name="selection-room"][value="'.$room->id.'"]')
        ->click('[data-testid="selection-take"]')
        ->waitFor('[data-testid="selection-finish"]')
        ->click('[data-testid="selection-finish"]')
        ->waitFor('[data-testid="selection-finish-confirm"]')
        ->click('[data-testid="selection-finish-confirm"]')
        ->assertNoJavaScriptErrors();

    expect($group->fresh()->teacher_id)->toBe($uno->id)
        ->and($world->entryOf($uno)->status)->not->toBe(SelectionEntryStatus::Waiting);

    visit('/login')
        ->fill('username', 'docente.dos')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/mi-espacio/oferta')
        ->assertSee('Docente Uno')
        ->assertNoJavaScriptErrors();
});
