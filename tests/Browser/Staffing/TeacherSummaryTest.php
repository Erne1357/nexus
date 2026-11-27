<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;

/*
| El Resumen del docente en el navegador (tarea 1.85, IN-03): el docente entra, ve su tablero con lo de hoy, su carga del
| periodo en planeación y su acceso, y a 390 px la página no se desborda.
*/

beforeEach(function () {
    $this->travelTo('2026-11-26 12:10:00');
    $planning = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-18', 'ends_on' => '2027-06-11']);
    $running = Term::factory()->create(['code' => '20263', 'name' => 'Ago-Dic 2026', 'starts_on' => '2026-08-17', 'ends_on' => '2026-12-11']);
    $campus = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $department = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $teacher = Teacher::factory()->adscribedTo($department)->create(['name' => 'Marisol Quiñones']);
    TeacherPosition::factory()->tiempoCompleto()->create(['teacher_id' => $teacher->id]);
    $user = User::factory()->withRole(Role::Docente)->create(['username' => 'marisol', 'name' => 'Marisol Quiñones']);
    $teacher->update(['user_id' => $user->id]);

    foreach ([[$running, 'A', 4, '16:00', '18:00', 'Programación Estructurada'], [$planning, 'B', 1, '10:00', '12:00', 'Estructura de Datos']] as [$term, $code, $day, $from, $to, $name]) {
        $group = Group::factory()->create([
            'term_id' => $term->id,
            'campus_id' => $campus->id,
            'subject_id' => Subject::factory()->create(['department_id' => $department->id, 'name' => $name, 'theory_hours' => 4, 'practice_hours' => 0])->id,
            'code' => $code,
            'teacher_id' => $teacher->id,
        ]);
        GroupSession::factory()->on($day, $from, $to)->create(['group_id' => $group->id]);
    }
});

it('IN-03: el docente entra y ve su tablero en el Resumen', function () {
    visit('/login')
        ->fill('username', 'marisol')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/resumen')
        ->assertSee('Tus clases de hoy, tu carga y lo que necesita tu atención.')
        ->assertSee('Marisol Quiñones')
        ->assertSee('Programación Estructurada')
        ->assertSee('Siguiente')
        ->assertSee('Ene-Jun 2027')
        ->assertSee('Estructura de Datos')
        ->assertSee('Tu acceso')
        ->assertNoJavaScriptErrors();
});

it('IN-03: a 390 px el tablero no se desborda, en claro y en oscuro', function (string $scheme) {
    $login = visit('/login');

    $page = ($scheme === 'oscuro' ? $login->inDarkMode() : $login->inLightMode())
        ->resize(390, 844)
        ->fill('username', 'marisol')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/resumen')
        ->assertSee('Tu acceso')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth');

    $page->screenshot(fullPage: true, filename: "teacher-summary-390-{$scheme}")
        ->assertNoJavaScriptErrors();
})->with(['claro', 'oscuro']);
