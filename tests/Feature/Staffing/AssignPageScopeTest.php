<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Staffing\AssignmentWorld;

/*
| Alcance de «Asignación docente» (tarea 3.9; reglas-de-negocio.md, "Aprobaciones y permisos"): ven el ranking el jefe
| y el jefe de proyecto docente de su departamento (y campus), la Subdirección y el admin; asignan solo los primeros.
| Lo de otro departamento u otro campus no se ve ni se toca.
*/

beforeEach(function () {
    $this->world = AssignmentWorld::create();
    $this->isc = $this->world->career('ISC');
    $this->poo = $this->world->subject('SCC-1013', 'Programación Orientada a Objetos');
    $this->group = $this->world->group($this->poo, $this->isc, 'D', 2, [[1, '10:00', '11:00']]);
    $this->teacher = $this->world->teacher('Ana Ruiz');
    $this->world->rank($this->group, [$this->teacher]);
    $this->cb = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
});

/**
 * @param  array<string, string>  $query
 */
function scopedAssignPage(User $user, array $query = []): Testable
{
    test()->withSession([Actor::ACTIVE_CAMPUS => test()->world->campus->id]);

    return Livewire::withQueryParams($query)->actingAs($user)->test('staffing::pages.assign');
}

it('el jefe de otro departamento no ve los grupos del departamento ni los toca', function () {
    $jefeCb = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->cb->id])->create();

    scopedAssignPage($jefeCb, ['depto' => 'SC'])->assertForbidden();

    scopedAssignPage($jefeCb)
        ->assertSee('Ciencias Básicas')
        ->assertDontSeeHtml('data-subject="SCC-1013"')
        ->call('assign', $this->group->id, $this->teacher->id)
        ->assertNotFound();

    expect($this->group->fresh()->teacher_id)->toBeNull();
});

it('el jefe de proyecto docente de otro campus no ve los grupos de este campus', function () {
    $otherCampus = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $proyecto = User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $otherCampus->id, 'department_id' => $this->world->department->id])->create();

    scopedAssignPage($proyecto, ['grupo' => $this->group->id])
        ->assertSee('Campus I')
        ->assertDontSeeHtml('data-subject="SCC-1013"')
        ->assertSet('groupId', '')
        ->call('toggleRanking', $this->group->id)
        ->assertNotFound();
});

it('el coordinador y el docente no entran', function (Role $role) {
    $user = $role === Role::Coordinador
        ? User::factory()->withRole($role, ['campus_id' => $this->world->campus->id, 'career_id' => $this->isc->id])->create()
        : User::factory()->withRole($role)->create();

    scopedAssignPage($user)->assertForbidden();
    $this->withSession([Actor::ACTIVE_CAMPUS => $this->world->campus->id])->actingAs($user)->get(route('staffing.assign'))->assertForbidden();
})->with([
    'coordinador' => [Role::Coordinador],
    'docente' => [Role::Docente],
]);

it('la Subdirección ve el departamento que elige y su ranking, sin «Asignar», y no puede asignar', function () {
    $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();

    scopedAssignPage($subdireccion, ['depto' => 'SC'])
        ->assertSeeHtml('data-subject="SCC-1013"')
        ->assertSee('Ana Ruiz')
        ->assertSeeHtml('data-testid="read-only"')
        ->assertDontSeeHtml('data-testid="assign-button"')
        ->assertDontSee('Cambiar')
        ->call('assign', $this->group->id, $this->teacher->id)
        ->assertForbidden();

    expect(GroupTeacherAssignment::query()->count())->toBe(0);
});

it('el admin ve y asigna', function () {
    scopedAssignPage(User::factory()->admin()->create(), ['depto' => 'SC'])
        ->assertSeeHtml('data-testid="assign-button"')
        ->call('assign', $this->group->id, $this->teacher->id, 'tarjetas');

    expect($this->group->fresh()->teacher_id)->toBe($this->teacher->id);
});

it('el menú muestra «Asignación docente» a quien ve el ranking y no al coordinador ni al docente', function () {
    $labels = function (User $user): array {
        $this->actingAs($user);

        return array_map(fn ($item) => $item->label, app(Navigation::class)->visibleTo(app(Actor::class)->forUser($user)));
    };

    expect($labels(User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->world->department->id])->create()))->toContain('Asignación docente')
        ->and($labels(User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->world->campus->id, 'department_id' => $this->world->department->id])->create()))->toContain('Asignación docente')
        ->and($labels(User::factory()->withRole(Role::Subdireccion)->create()))->toContain('Asignación docente')
        ->and($labels(User::factory()->admin()->create()))->toContain('Asignación docente')
        ->and($labels(User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->world->campus->id, 'career_id' => $this->isc->id])->create()))->not->toContain('Asignación docente')
        ->and($labels(User::factory()->withRole(Role::Docente)->create()))->not->toContain('Asignación docente');
});
