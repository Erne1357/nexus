<?php

use App\Modules\Academic\Enums\SubjectKind;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\User;

/*
| CRUD de Materias en el navegador: el admin entra por el menú, da de alta una materia y la edita
| desde la UI. Materias es del área Académico, la del inicio.
*/

beforeEach(function () {
    $this->sis = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    Subject::factory()->create(['code' => 'ACF-0901', 'name' => 'Cálculo Diferencial', 'department_id' => $this->sis->id]);
    User::factory()->admin()->create(['username' => 'admin', 'name' => 'Ana Admin']);
});

it('el admin da de alta una materia y luego la edita desde la UI', function () {
    $page = visit('/login')
        ->fill('username', 'admin')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->click('#nexus-menu a[data-label="Materias"]')
        ->assertPathIs('/academic/subjects')
        ->assertSee('Cálculo Diferencial')
        ->press('Nueva materia')
        ->fill('code', 'scd-1003')
        ->fill('name', 'Arquitectura de Computadoras')
        ->select('department_id', $this->sis->id)
        ->fill('theory_hours', '2')
        ->fill('practice_hours', '3')
        ->fill('credits', '5')
        ->select('kind', 'especialidad')
        ->press('Guardar')
        ->waitForText('Materia creada')
        ->assertSee('Arquitectura de Computadoras')
        ->assertNoJavaScriptErrors();

    expect(Subject::where('code', 'SCD-1003')->sole())
        ->name->toBe('Arquitectura de Computadoras')
        ->credits->toBe(5)
        ->kind->toBe(SubjectKind::Especialidad);

    $page->click('@edit-SCD-1003')
        ->assertValue('code', 'SCD-1003')
        ->fill('credits', '6')
        ->press('Guardar')
        ->waitForText('Materia actualizada')
        ->assertSee('2-3-6')
        ->assertNoJavaScriptErrors();

    expect(Subject::where('code', 'SCD-1003')->sole()->credits)->toBe(6);
});
