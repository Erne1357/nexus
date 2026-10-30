<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherAvailability;

/*
| Disponibilidad docente en el navegador (DESIGN.md, "Cuadrícula día x hora"): el jefe de Sistemas y Computación
| abre la pestaña Disponibilidad de la ficha, entra al editor, pinta tres horas del lunes como no disponibles
| arrastrando, elige «Preferido» con la tecla P y marca dos horas del miércoles con clic, guarda, recarga y las ve.
*/

beforeEach(function () {
    $this->sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-25', 'ends_on' => '2027-06-11']);
    $this->ana = Teacher::factory()->adscribedTo($this->sc)->create(['employee_number' => '1000001', 'name' => 'Ana Pérez']);

    User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sc->id])
        ->create(['username' => 'jefe.sc', 'name' => 'Julia Jefa']);
});

it('el jefe marca tres horas como no disponibles y dos como preferidas, guarda, recarga y las ve', function () {
    $kindOf = fn (int $day, int $block): string => "document.querySelector('[data-d=\"{$day}\"][data-h=\"{$block}\"]').dataset.kind ?? ''";

    $page = visit('/login')
        ->fill('username', 'jefe.sc')
        ->fill('password', 'password')
        ->press('Entrar')
        ->navigate('/faculty/teachers/'.$this->ana->id.'?tab=disponibilidad')
        ->waitForText('Editar disponibilidad')
        ->click('[data-testid="edit-availability"]')
        ->waitForText('Por día')
        ->assertPathIs('/faculty/teachers/'.$this->ana->id.'/availability')
        ->drag('[data-d="0"][data-h="0"]', '[data-d="0"][data-h="2"]')
        ->keys('[data-d="0"][data-h="2"]', 'p')
        ->click('[data-d="2"][data-h="3"]')
        ->click('[data-d="2"][data-h="4"]')
        ->assertSee('5 bloques sin guardar')
        ->assertScript($kindOf(0, 1), 'unavailable')
        ->assertScript($kindOf(2, 4), 'preferred')
        ->assertScript("document.querySelector('[data-testid=availability-figures]').textContent.replace(/\\s+/g, ' ').trim()", 'Disponible 67 h de 70 Preferido 2 h No disponible 3 h Horas base · Ene-Jun 2027 0 h · 0 plazas')
        ->click('[data-testid="save-availability"]')
        ->waitForText('Disponibilidad guardada')
        ->assertDontSee('bloques sin guardar')
        ->refresh()
        ->waitForText('Por día')
        ->assertScript($kindOf(0, 0), 'unavailable')
        ->assertScript($kindOf(0, 2), 'unavailable')
        ->assertScript($kindOf(0, 3), '')
        ->assertScript($kindOf(2, 3), 'preferred')
        ->assertScript($kindOf(2, 4), 'preferred')
        ->assertSee('7:00–10:00')
        ->assertSee('10:00–12:00')
        ->assertNoJavaScriptErrors();

    expect(TeacherAvailability::query()->orderBy('day')->get(['day', 'starts_at', 'ends_at', 'kind'])->map(fn (TeacherAvailability $row): string => "{$row->day} {$row->starts_at}-{$row->ends_at} {$row->kind->value}")->all())
        ->toBe(['1 07:00:00-10:00:00 unavailable', '3 10:00:00-12:00:00 preferred']);
});
