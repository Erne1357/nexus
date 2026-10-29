<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;

/*
| Carreras en el navegador: el admin, con C2 como único campus (su campus activo), entra por el menú,
| ofrece una carrera en C2 y luego deja de ofrecerla desde la UI.
*/

beforeEach(function () {
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $department = Department::factory()->create(['code' => 'SIS']);
    $this->career = Career::factory()->create(['code' => 'ISC', 'name' => 'Ingeniería en Sistemas', 'department_id' => $department->id]);
    User::factory()->admin()->create(['username' => 'admin', 'name' => 'Ana Admin']);
});

it('el admin ofrece una carrera en C2 y luego deja de ofrecerla desde la UI', function () {
    $page = visit('/login')
        ->fill('username', 'admin')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->click('#nexus-menu a[data-label="Carreras"]')
        ->assertPathIs('/academic/careers')
        ->assertSee('Ingeniería en Sistemas')
        ->assertSee('Se ofrece en C2')
        ->click('@offering-ISC')
        ->waitForText('Carrera ofrecida')
        ->assertNoJavaScriptErrors();

    expect(CareerCampus::where('career_id', $this->career->id)->where('campus_id', $this->c2->id)->sole()->is_active)->toBeTrue();

    $page->click('@offering-ISC')
        ->waitForText('Carrera fuera de la oferta')
        ->assertNoJavaScriptErrors();

    expect(CareerCampus::where('career_id', $this->career->id)->sole()->is_active)->toBeFalse();
});
