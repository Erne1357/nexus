<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Staffing\Enums\SelectionEntryStatus;
use Carbon\CarbonImmutable;
use Tests\Support\Staffing\SelectionWorld;

/*
| Flujo de la orden de selección en el navegador (tarea 1.33): con la selección publicada, el docente en turno abre la
| oferta, toma un grupo con su salón desde la pestaña Lista y termina su carga; el docente siguiente ve el grupo tomado.
| Son dos pruebas, cada una con su sesión. Nombres ficticios.
*/

function selectionFlowTeacher(Teacher $teacher, string $username): void
{
    RoleAssignment::factory()->for($teacher->user)->create(['role' => Role::Docente]);
    $teacher->user->update(['username' => $username]);
}

it('el docente en su turno toma grupo y salón y termina su carga', function () {
    $world = SelectionWorld::create();
    $uno = $world->teacher(base: 5, name: 'Docente Uno');
    selectionFlowTeacher($uno, 'docente.uno');
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
        ->waitForText('Terminaste tu carga')
        ->assertNoJavaScriptErrors();

    expect($group->fresh()->teacher_id)->toBe($uno->id)
        ->and($world->entryOf($uno)->status)->not->toBe(SelectionEntryStatus::Waiting);
});

it('el docente siguiente ve el grupo tomado por el anterior', function () {
    $world = SelectionWorld::create();
    $uno = $world->teacher(base: 5, name: 'Docente Uno');
    $dos = $world->teacher(base: 5, slotStartsAt: CarbonImmutable::now()->addMinutes(25), position: 2, name: 'Docente Dos');
    selectionFlowTeacher($uno, 'docente.uno');
    selectionFlowTeacher($dos, 'docente.dos');
    $group = $world->group('A', [[1, '09:00', '10:00'], [3, '09:00', '10:00']]);
    $room = $world->room('A1');
    $group->update(['teacher_id' => $uno->id]);
    GroupSession::query()->where('group_id', $group->id)->update(['room_id' => $room->id]);

    visit('/login')
        ->fill('username', 'docente.dos')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/mi-espacio/oferta')
        ->assertSee('En turno: Docente Uno')
        ->assertSee('Docente Uno')
        ->assertNoJavaScriptErrors();
});
