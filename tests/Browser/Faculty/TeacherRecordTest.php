<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;

/*
| Ficha del docente en el navegador: el jefe de Sistemas y Computación entra a Docentes, abre la ficha
| de un docente, agrega una plaza en el modal y ve sus horas base del periodo actualizadas.
*/

beforeEach(function () {
    $this->sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    Term::factory()->current()->create(['name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-25', 'ends_on' => '2027-06-11']);
    $this->ana = Teacher::factory()->adscribedTo($this->sc)->create(['employee_number' => '1000001', 'name' => 'Ana Pérez']);
    TeacherPosition::factory()->for($this->ana)->medioTiempo()->create();

    User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sc->id])
        ->create(['username' => 'jefe.sc', 'name' => 'Julia Jefa']);
});

it('el jefe de SC abre la ficha, agrega una plaza y ve las horas base actualizadas', function () {
    visit('/login')
        ->fill('username', 'jefe.sc')
        ->fill('password', 'password')
        ->press('Entrar')
        ->navigate('/faculty/teachers')
        ->click('[data-testid="record-1000001"]')
        ->waitForText('Horas base')
        ->assertPathIs('/faculty/teachers/'.$this->ana->id)
        ->assertSee('Ana Pérez')
        ->assertScript("document.querySelector('[data-testid=teacher-figures] .font-mono').textContent.trim()", '20 h')
        ->click('[data-testid="tab-plazas"]')
        ->click('[data-testid="add-position"]')
        ->waitForText('Agregar plaza')
        ->select('kind', 'tiempo_completo')
        ->press('Guardar plaza')
        ->waitForText('Plaza agregada')
        ->assertScript("document.querySelector('[data-testid=teacher-figures] .font-mono').textContent.trim()", '60 h')
        ->assertSee('Tiempo completo')
        ->assertNoJavaScriptErrors();

    expect($this->ana->positions()->count())->toBe(2);
});

it('el jefe de SC no abre la ficha de un docente de otro departamento', function () {
    $other = Teacher::factory()->adscribedTo(Department::factory()->create(['code' => 'II']))->create(['name' => 'Beto Ruiz']);

    visit('/login')
        ->fill('username', 'jefe.sc')
        ->fill('password', 'password')
        ->press('Entrar')
        ->navigate('/faculty/teachers/'.$other->id)
        ->assertDontSee('Beto Ruiz');
});
