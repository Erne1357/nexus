<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Staffing\Models\StaffingRanking;

/*
| Vista docente en el navegador (tarea 3.10): el docente abre «Oferta del departamento», toca un grupo, ve su propio
| desglose en el panel y pasa a «Mi carga».
*/

beforeEach(function () {
    $term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    $campus = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $department = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    AcademicLoad::factory()->create(['term_id' => $term->id, 'campus_id' => $campus->id, 'department_id' => $department->id, 'status' => LoadStatus::Staffing]);

    $me = Teacher::factory()->adscribedTo($department)->create(['name' => 'Marisol Quiñones']);
    $colleague = Teacher::factory()->adscribedTo($department)->create(['name' => 'Óscar Nájera']);
    $user = User::factory()->withRole(Role::Docente)->create(['username' => 'marisol', 'name' => 'Marisol Quiñones']);
    $me->update(['user_id' => $user->id]);

    $subject = Subject::factory()->create(['department_id' => $department->id, 'name' => 'Fundamentos de Programación', 'theory_hours' => 4, 'practice_hours' => 0]);
    $this->open = Group::factory()->create(['term_id' => $term->id, 'campus_id' => $campus->id, 'subject_id' => $subject->id, 'code' => '1A', 'teacher_id' => $colleague->id]);
    $mine = Group::factory()->create(['term_id' => $term->id, 'campus_id' => $campus->id, 'subject_id' => $subject->id, 'code' => '1B', 'teacher_id' => $me->id]);
    GroupSession::factory()->on(2, '11:00', '13:00')->create(['group_id' => $this->open->id]);
    GroupSession::factory()->on(1, '07:00', '09:00')->create(['group_id' => $mine->id]);
    StaffingRanking::factory()->create(['group_id' => $this->open->id, 'teacher_id' => $me->id, 'rank' => 2, 'score' => 12,
        'breakdown' => ['base_hours' => 40, 'history_count' => 5, 'qualification' => 'preferente', 'evaluation' => 4.5, 'remaining_hours' => 8, 'flags' => []]]);
    StaffingRanking::factory()->create(['group_id' => $this->open->id, 'teacher_id' => $colleague->id, 'rank' => 1, 'score' => 99.5,
        'breakdown' => ['base_hours' => 40, 'history_count' => 9, 'qualification' => 'preferente', 'evaluation' => 5.0, 'remaining_hours' => 4, 'flags' => []]]);
});

it('el docente abre la oferta, ve su lugar y desglose en el panel y pasa a Mi carga', function () {
    visit('/login')
        ->fill('username', 'marisol')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/mi-espacio/oferta')
        ->assertSee('Oferta del departamento')
        ->assertSee('Semestre 1')
        ->assertSee('Fundamentos de Programación')
        ->assertSee('2 de 2')
        ->assertSee('Óscar Nájera')
        ->click('[data-testid="offer-group-'.$this->open->id.'"]')
        ->waitForText('Tu desglose')
        ->assertSee('Tu lugar')
        ->assertSee('Preferente')
        ->assertDontSee('99.5')
        ->navigate('/mi-espacio/mi-carga')
        ->assertSee('Mi carga')
        ->assertSee('Lunes')
        ->assertSee('07:00–09:00')
        ->assertSee('Restantes')
        ->assertNoJavaScriptErrors();
});
