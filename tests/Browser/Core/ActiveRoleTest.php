<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;

/*
| El perfil activo en el navegador: quien tiene dos puestos los ve como renglones de «Actuando como» en
| la credencial; elegir uno la manda a Inicio y el menú muestra solo lo que ese puesto puede hacer.
*/

it('una persona con dos puestos cambia de perfil y ve menú e Inicio distintos', function () {
    $c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    Term::factory()->current()->create(['name' => 'Ene-Jun 2027']);
    $department = Department::factory()->create(['name' => 'Sistemas y Computación']);
    $career = Career::factory()->create(['code' => 'ISC', 'name' => 'Ingeniería en Sistemas Computacionales']);

    User::factory()
        ->withRole(Role::JefeDepartamento, ['department_id' => $department->id, 'campus_id' => $c2->id])
        ->withRole(Role::Coordinador, ['campus_id' => $c2->id, 'career_id' => $career->id])
        ->create(['username' => 'doble', 'name' => 'Dora Doble', 'last_role' => Role::JefeDepartamento]);

    $page = visit('/login')
        ->fill('username', 'doble')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->waitForText('Validas los catálogos de tu departamento, asignas docentes y salones a los grupos de sus materias y cierras su carga.')
        ->assertSeeIn('[data-testid="role-block"]', 'Jefe de departamento académico')
        ->assertDontSeeIn('[data-testid="role-block"]', 'Coordinador de carrera')
        ->assertVisible('a[data-label="Bandeja del departamento"]')
        ->assertMissing('a[data-label="Propuesta de carrera"]')
        ->click('@credential')
        ->assertSee('Actuando como')
        ->assertSee('Coordinador de carrera');

    $page->resize(390, 844)->screenshot(filename: 'active-role-390');
    $page->resize(1440, 900)->screenshot(filename: 'active-role-1440');

    $page->click('[data-testid="role-switcher"] input[value="coordinador"]')
        ->waitForText('Analizas el avance de los alumnos de tu carrera, propones qué materias y cuántos grupos abrir y armas la propuesta de horarios por semestre.')
        ->assertPathIs('/')
        ->assertSeeIn('[data-testid="role-block"]', 'Coordinador de carrera')
        ->assertDontSeeIn('[data-testid="role-block"]', 'Jefe de departamento académico')
        ->assertVisible('a[data-label="Propuesta de carrera"]')
        ->assertMissing('a[data-label="Bandeja del departamento"]')
        ->assertNoJavaScriptErrors();
});
