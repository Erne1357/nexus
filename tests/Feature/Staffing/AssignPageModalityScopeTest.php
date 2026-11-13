<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Staffing\AssignmentWorld;

/*
| Alcance de «Asignación docente» (maqueta U2, opción A «Selectores en la página»; DESIGN.md, «Alcance de la carga»):
| la página es de una modalidad (`?modalidad=`, M1; sin select con una sola, MS16) con los grupos de todos sus campus y
| el campus como filtro con su cifra (`?campus=`, «Todos» por omisión). Se lee la modalidad entera y se escribe con
| campus (MS6): un jefe de proyecto docente limitado a C2 ve los grupos de C1 de solo consulta, y asignar uno es 403 de
| la policy con el campus del grupo, no un 404 de la página.
*/

beforeEach(function () {
    $this->world = AssignmentWorld::create();
    $this->pres = modalityOf('PRES');
    $this->ead = modalityOf('EAD');
    $this->c2 = $this->world->campus;
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->isc = $this->world->career('ISC');
    $this->poo = $this->world->subject('SCC-1013', 'Programación Orientada a Objetos');
    $this->groupC2 = $this->world->group($this->poo, $this->isc, 'D', 2, [[1, '10:00', '11:00']]);
    $this->groupC1 = $this->world->group($this->poo, $this->isc, 'E', 2, [[2, '10:00', '11:00']], ['campus_id' => $this->c1->id]);
    $this->groupC1b = $this->world->group($this->poo, $this->isc, 'F', 2, [[3, '10:00', '11:00']], ['campus_id' => $this->c1->id]);
    $this->teacher = $this->world->teacher('Ana Ruiz');
    $this->world->rank($this->groupC2, [$this->teacher]);
    $this->world->rank($this->groupC1, [$this->teacher]);
    $this->world->rank($this->groupC1b, [$this->teacher]);
    $this->jpdC2 = User::factory()->withRole(Role::JefeProyectoDocente, ['modality_id' => $this->pres->id, 'campus_id' => $this->c2->id, 'department_id' => $this->world->department->id])->create();
});

/**
 * @param  array<string, string>  $query
 */
function assignInModality(User $user, array $query = []): Testable
{
    onCampusPage(null);

    return Livewire::withQueryParams(['depto' => 'SC', ...$query])->actingAs($user)->test('staffing::pages.assign');
}

it('MS6: el jefe de proyecto docente de PRES limitado a C2 ve los grupos de C1 y C2, cada uno con su campus, y los de C1 son de consulta', function () {
    assignInModality($this->jpdC2, ['vista' => 'materia', 'estado' => 'todos'])
        ->assertOk()
        ->assertSet('campus', null)
        ->assertSeeHtml('data-group="'.$this->groupC1->id.'"')
        ->assertSeeHtml('data-group="'.$this->groupC2->id.'"')
        ->assertSeeHtml('data-testid="group-campus-'.$this->groupC1->id.'"')
        ->assertSee('Asignas solo los grupos de C2; los de C1 son de consulta.')
        ->call('toggleRanking', $this->groupC1->id)
        ->assertHasNoErrors()
        ->assertSeeHtml('title="Solo consulta: asignas los grupos de C2."');
});

it('MS6: asignar en un grupo de C1 es 403 de la policy y en uno de C2 asigna', function () {
    $page = assignInModality($this->jpdC2);

    $page->call('assign', $this->groupC1->id, $this->teacher->id, 'tarjetas')->assertForbidden();
    expect(GroupTeacherAssignment::query()->where('group_id', $this->groupC1->id)->exists())->toBeFalse();

    assignInModality($this->jpdC2)->call('assign', $this->groupC2->id, $this->teacher->id, 'tarjetas')->assertHasNoErrors();
    expect($this->groupC2->fresh()->teacher_id)->toBe($this->teacher->id);
});

it('un jefe de proyecto docente de PRES sin campus asigna en C1 y en C2', function () {
    $jpd = User::factory()->withRole(Role::JefeProyectoDocente, ['modality_id' => $this->pres->id, 'department_id' => $this->world->department->id])->create();

    assignInModality($jpd)
        ->assertDontSee('Asignas solo los grupos de')
        ->call('assign', $this->groupC1->id, $this->teacher->id, 'tarjetas')
        ->assertHasNoErrors();
    assignInModality($jpd)->call('assign', $this->groupC2->id, $this->teacher->id, 'tarjetas')->assertHasNoErrors();

    expect($this->groupC1->fresh()->teacher_id)->toBe($this->teacher->id)
        ->and($this->groupC2->fresh()->teacher_id)->toBe($this->teacher->id);
});

it('U2: el filtro de campus lleva los grupos de la carga en cada campus y deja ver solo los de uno, sin cambiar de carga', function () {
    $page = assignInModality($this->jpdC2, ['vista' => 'materia', 'estado' => 'todos'])
        ->assertSeeHtml('data-testid="campus-count-todos">3<')
        ->assertSeeHtml('data-testid="campus-count-C1">2<')
        ->assertSeeHtml('data-testid="campus-count-C2">1<')
        ->assertSee('Filtra los grupos y su avance; la carga es de toda la modalidad.');

    $page->set('campus', 'C1')
        ->assertSet('campus', 'C1')
        ->assertSeeHtml('data-group="'.$this->groupC1->id.'"')
        ->assertDontSeeHtml('data-group="'.$this->groupC2->id.'"')
        ->assertSet('modalidad', 'PRES');

    $page->set('campus', null)->assertSeeHtml('data-group="'.$this->groupC2->id.'"');
});

it('U2: un campus que no es de la modalidad, inactivo o `?campus[]=` es «Todos»', function (mixed $campus) {
    Campus::factory()->create(['code' => 'C9', 'is_active' => false]);

    assignInModality($this->jpdC2, ['campus' => $campus, 'estado' => 'todos'])
        ->assertOk()
        ->assertSeeHtml('data-group="'.$this->groupC1->id.'"')
        ->assertSeeHtml('data-group="'.$this->groupC2->id.'"');
})->with([
    'inactivo' => ['C9'],
    'no existe' => ['ZZ'],
    'arreglo' => [['C1']],
]);

it('?grupo= de C1 con el filtro en C2 abre el grupo y quita el filtro', function () {
    assignInModality($this->jpdC2, ['campus' => 'C2', 'grupo' => $this->groupC1->id])
        ->assertSet('groupId', $this->groupC1->id)
        ->assertSet('campus', null)
        ->assertSeeHtml('data-group="'.$this->groupC1->id.'"');
});

it('M1, MS16: con una sola modalidad no hay select y la página dice Presencial; con EAD activa el select la cambia', function () {
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->world->department->id])->create();

    assignInModality($jefe)->assertDontSeeHtml('data-testid="modality-scope"')->assertSeeHtml('data-testid="assign-modality">Presencial<');

    $this->ead->update(['is_active' => true]);
    TermOpening::factory()->create(['term_id' => $this->world->term->id, 'modality_id' => $this->pres->id]);

    assignInModality($jefe)
        ->assertSeeHtml('data-testid="modality-scope"')
        ->assertSet('modalidad', 'PRES')
        ->set('modalidad', 'EAD')
        ->assertSet('modalidad', 'EAD')
        ->assertDontSeeHtml('data-group="'.$this->groupC1->id.'"')
        ->assertSee('SC no tiene carga en EAD para este periodo.');
});

it('cambiar a una modalidad donde no ve el departamento abre el primero que ve ahí, sin 403', function () {
    $this->ead->update(['is_active' => true]);
    TermOpening::factory()->create(['term_id' => $this->world->term->id, 'modality_id' => $this->pres->id]);
    $cb = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $both = User::factory()
        ->withRole(Role::JefeProyectoDocente, ['modality_id' => $this->pres->id, 'department_id' => $this->world->department->id])
        ->withRole(Role::JefeProyectoDocente, ['modality_id' => $this->ead->id, 'department_id' => $cb->id])
        ->create();

    assignInModality($both)
        ->assertSet('department', 'SC')
        ->set('modalidad', 'EAD')
        ->assertOk()
        ->assertSet('modalidad', 'EAD')
        ->assertSet('department', 'CB');
});

it('otra modalidad: un jefe de proyecto docente de EAD no ve ni asigna los grupos de PRES aunque pida PRES en la URL', function () {
    $this->ead->update(['is_active' => true]);
    $jpdEad = User::factory()->withRole(Role::JefeProyectoDocente, ['modality_id' => $this->ead->id, 'department_id' => $this->world->department->id])->create();

    assignInModality($jpdEad, ['modalidad' => 'PRES'])
        ->assertSet('modalidad', 'EAD')
        ->assertDontSeeHtml('data-group="'.$this->groupC1->id.'"')
        ->assertDontSeeHtml('data-group="'.$this->groupC2->id.'"')
        ->call('assign', $this->groupC2->id, $this->teacher->id, 'tarjetas')
        ->assertNotFound();

    expect($this->groupC2->fresh()->teacher_id)->toBeNull();
});
