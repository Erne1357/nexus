<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;

/*
| Ficha del alumno en el navegador (tarea 1.6): el coordinador entra a Alumnos desde el menú, abre la ficha de un
| alumno de su carrera, ve su retícula y pasa al kárdex.
*/

beforeEach(function () {
    $campus = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $career = Career::factory()->create(['code' => 'ISIC', 'name' => 'Ingeniería en Sistemas Computacionales']);
    $plan = StudyPlan::factory()->for($career)->create(['code' => 'ISIC-2010-224', 'total_credits' => 260]);
    $calculus = Subject::factory()->create(['code' => 'ACF-0901', 'name' => 'Cálculo Diferencial']);
    $integral = Subject::factory()->create(['code' => 'ACF-0902', 'name' => 'Cálculo Integral']);
    placeInPlan($plan, $calculus);
    placeInPlan($plan, $integral, 2);
    seriate($plan, $integral, $calculus);
    $student = Student::factory()->for($plan)->create(['control_number' => '24111234', 'name' => 'Daniela Ortiz Mendoza', 'campus_id' => $campus->id]);
    KardexRecord::factory()->for($student)->for($calculus)->create(['term_code' => '20253', 'grade' => 88]);

    User::factory()->withRole(Role::Coordinador, ['campus_id' => $campus->id, 'career_id' => $career->id])->create(['username' => '19110002']);
});

it('el coordinador abre la ficha de un alumno desde el listado y pasa al kárdex', function () {
    visit('/login')
        ->fill('username', '19110002')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/students')
        ->assertSee('Daniela Ortiz Mendoza')
        ->click('@show-24111234')
        ->assertSee('Cálculo Integral')
        ->assertSee('Puede seleccionarla')
        ->click('@tab-kardex')
        ->assertSee('Ago–Dic 2025')
        ->assertSee('1 · ordinaria')
        ->assertNoJavaScriptErrors();
});
