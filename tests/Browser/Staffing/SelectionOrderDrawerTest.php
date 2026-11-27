<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Staffing\Enums\SelectionEntrySource;
use App\Modules\Staffing\Enums\SelectionEntryStatus;
use App\Modules\Staffing\Enums\SelectionStatus;
use Carbon\CarbonImmutable;
use Tests\Support\Staffing\SelectionWorld;

/*
| El drawer del orden de selección (tarea 1.52) no se desborda ni recorta a lo ancho: de celular a escritorio cada
| renglón cabe en el drawer, sin scroll horizontal. Los renglones más anchos: nombres largos, empate de turno, escalón
| con rebaja, elección previa («falta confirmar» y «confirmó»), quien no viene en el CSV y la ronda extra. Nombres ficticios.
*/

it('cada renglón del drawer del orden cabe sin scroll ni recorte en 390 y 1280 px', function (int $width, int $height) {
    $this->freezeTime();
    $world = SelectionWorld::create(SelectionStatus::ExtraRound);
    $now = CarbonImmutable::now();
    $uno = $world->teacher(base: 5, name: 'Docente Uno con un nombre bastante largo para probar el ajuste de renglón', entry: ['tier' => 5, 'plaza_tier' => 0, 'failed_criteria' => 2, 'auto_eval' => false, 'status' => SelectionEntryStatus::PendingExtra, 'extra_slot_starts_at' => $now->addDay(), 'extra_slot_ends_at' => $now->addDay()->addMinutes(30)]);
    RoleAssignment::factory()->for($uno->user)->create(['role' => Role::Docente]);
    $uno->user->update(['username' => 'docente.orden']);
    $world->teacher(base: 8, position: 2, slotStartsAt: $now->addMinutes(30), name: 'Docente Empate Dos', entry: ['slot_index' => 1, 'extra_slot_starts_at' => $now->addDay(), 'extra_slot_ends_at' => $now->addDay()->addMinutes(30)]);
    $world->teacher(base: 8, position: 3, slotStartsAt: $now->addMinutes(30), name: 'Docente Empate Tres', entry: ['slot_index' => 1]);
    $world->teacher(base: 8, position: 4, name: 'Docente Nuevo Ingreso sin datos del archivo del CSV', entry: ['source' => SelectionEntrySource::Appended, 'hired_on' => '2021-08-16', 'auto_eval' => null, 'prior_compliance' => null, 'partials_1' => null, 'partials_2' => null]);
    $world->teacher(name: 'Docente Previo Pendiente de confirmar sus horas', position: 0, entry: ['status' => SelectionEntryStatus::Done, 'prior_choice_at' => $now, 'prior_choice_confirm_required_at' => $now, 'slot_starts_at' => null, 'slot_ends_at' => null]);
    $world->teacher(name: 'Docente Previo Confirmó', position: 0, entry: ['status' => SelectionEntryStatus::Done, 'prior_choice_at' => $now, 'prior_choice_confirm_required_at' => $now, 'out_of_band_acknowledged_at' => $now, 'slot_starts_at' => null, 'slot_ends_at' => null]);
    $world->group('A', [[1, '09:00', '10:00'], [3, '09:00', '10:00']]);

    $page = visit('/login')
        ->resize($width, $height)
        ->fill('username', 'docente.orden')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/mi-espacio/oferta')
        ->click('[data-testid="selection-order-open"]')
        ->assertVisible('[data-testid="selection-order"]')
        ->assertSee('Docente Empate Dos')
        ->assertSee('falta confirmar')
        ->assertSee('confirmó')
        ->assertSee('empate')
        ->assertSee('ronda extra');

    // El cuerpo no se desborda, ningún elemento trae su propio scroll horizontal y cada renglón cabe entero en el drawer.
    $report = $page->script('(() => {
        const body = document.querySelector(\'[data-testid="selection-order"]\');
        const edge = body.getBoundingClientRect().right;
        const rows = [...body.querySelectorAll(\'li\')];
        return {
            body: body.scrollWidth - body.clientWidth,
            scrollers: [...body.querySelectorAll(\'*\')].filter((e) => [\'auto\', \'scroll\'].includes(getComputedStyle(e).overflowX) && e.scrollWidth > e.clientWidth + 1).length,
            rows: rows.length,
            clipped: rows.filter((li) => li.scrollWidth > li.clientWidth + 1 || li.getBoundingClientRect().right > edge + 1 || [...li.querySelectorAll(\'*\')].some((e) => e.getBoundingClientRect().right > li.getBoundingClientRect().right + 1)).length,
        };
    })()');

    expect($report['rows'])->toBe(6)
        ->and($report['body'])->toBeLessThanOrEqual(0)
        ->and($report['scrollers'])->toBe(0)
        ->and($report['clipped'])->toBe(0);
    $page->assertNoJavaScriptErrors();
})->with([[390, 844], [1280, 800]]);
