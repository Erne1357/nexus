<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use Tests\Support\Staffing\AssignmentWorld;

/*
| Asignación docente por modalidad con el campus como filtro (maqueta U2, opción A; MS6) en el navegador: un jefe de
| proyecto docente de Presencial limitado a C2 ve los grupos de C1 y C2 de la carga del departamento, cada uno con su
| campus; los de C1 son de consulta (con su motivo) y asigna en los de C2. El filtro de campus deja solo los de un campus.
*/

beforeEach(function () {
    $world = AssignmentWorld::create();
    $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $subject = $world->subject('SCC-1013', 'Programación Orientada a Objetos');
    $isc = $world->career('ISC');
    $this->inC2 = $world->group($subject, $isc, 'D', 2, [[1, '10:00', '11:00'], [3, '10:00', '11:00']]);
    $this->inC1 = $world->group($subject, $isc, 'E', 2, [[2, '10:00', '11:00'], [4, '10:00', '11:00']], ['campus_id' => $c1->id]);
    $this->teacher = $world->teacher('Ana Ruiz');
    $world->rank($this->inC2, [$this->teacher]);
    $world->rank($this->inC1, [$this->teacher]);

    User::factory()->withRole(Role::JefeProyectoDocente, ['modality_id' => modalityOf('PRES')->id, 'campus_id' => $world->campus->id, 'department_id' => $world->department->id])->create(['username' => '19110077']);
});

it('MS6: un jefe de proyecto docente de C2 ve los grupos de C1 y asigna solo en C2', function () {
    $page = visit('/login')
        ->fill('username', '19110077')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/staffing/assign?estado=todos')
        ->assertSee('Asignación docente')
        ->assertSee('Asignas solo los grupos de C2; los de C1 son de consulta.')
        ->assertPresent('[data-testid="group-campus-'.$this->inC1->id.'"]')
        ->assertPresent('[data-group="'.$this->inC1->id.'"] [data-testid="assign-disabled"]')
        ->click('[data-group="'.$this->inC2->id.'"] [data-testid="assign-button"]')
        ->assertSee('Asignaste a Ana Ruiz')
        ->click('[data-testid="campus-filter-C1"]')
        ->assertPresent('[data-group="'.$this->inC1->id.'"]')
        ->assertNotPresent('[data-group="'.$this->inC2->id.'"]')
        ->assertQueryStringHas('campus', 'C1')
        ->assertNoJavaScriptErrors();

    expect($this->inC2->fresh()->teacher_id)->toBe($this->teacher->id)
        ->and($this->inC1->fresh()->teacher_id)->toBeNull()
        ->and(GroupTeacherAssignment::query()->where('group_id', $this->inC1->id)->exists())->toBeFalse();
});
