<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use Tests\Support\Staffing\AssignmentWorld;

/*
| Asignación docente en el navegador (tarea 3.9): el jefe asigna al primer lugar del ranking con un clic y después
| cambia al docente por el segundo lugar, con el motivo que pide HC-16.
*/

beforeEach(function () {
    $world = AssignmentWorld::create();
    $subject = $world->subject('SCC-1013', 'Programación Orientada a Objetos');
    $this->group = $world->group($subject, $world->career('ISC'), 'D', 2, [[1, '10:00', '11:00'], [3, '10:00', '11:00']]);
    $this->teachers = [$world->teacher('Ana Ruiz'), $world->teacher('Beto Salas'), $world->teacher('Carla Díaz')];
    $world->rank($this->group, $this->teachers);

    User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $world->department->id])->create(['username' => '19110049']);
});

it('HC-16: el jefe asigna al primer lugar y luego al segundo con un motivo', function () {
    visit('/login')
        ->fill('username', '19110049')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/staffing/assign')
        ->assertSee('Asignación docente')
        ->assertSee('Programación Orientada a Objetos')
        ->click('[data-testid="assign-button"][data-rank="1"]')
        ->assertSee('Asignaste a Ana Ruiz')
        ->assertSee('Con docente')
        ->click('@change-teacher')
        ->click('[data-testid="assign-button"][data-rank="2"]')
        ->assertSee('Ocupa el lugar 2 de 3 del ranking.')
        ->fill('@reason-input', 'Coordina el laboratorio de la materia.')
        ->click('@confirm-assign')
        ->assertSee('Asignaste a Beto Salas')
        ->assertNoJavaScriptErrors();

    $history = GroupTeacherAssignment::query()->where('group_id', $this->group->id)->orderBy('assigned_at')->get();

    expect($this->group->fresh()->teacher_id)->toBe($this->teachers[1]->id)
        ->and($history)->toHaveCount(2)
        ->and($history[0]->replaced_at)->not->toBeNull()
        ->and($history[1])
        ->ranking_position_at_assignment->toBe(2)
        ->ranking_size_at_assignment->toBe(3)
        ->reason->toBe('Coordina el laboratorio de la materia.');
});
