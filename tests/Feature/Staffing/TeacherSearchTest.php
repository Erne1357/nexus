<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Staffing\Queries\TeacherSearchQuery;
use Livewire\Livewire;
use Tests\Support\Staffing\AssignmentWorld;

/*
| «Buscar otro docente» (HC-21, tarea 1.58): docentes activos de cualquier departamento por nombre o RFC, desde 3
| caracteres y a lo más 10; asignar a uno buscado pide el motivo de HC-16.
*/

beforeEach(function () {
    $this->world = AssignmentWorld::create();
    $this->isc = $this->world->career('ISC');
    $this->poo = $this->world->subject('SCC-1013', 'Programación Orientada a Objetos');
    $this->group = $this->world->group($this->poo, $this->isc, 'D', 2, [[1, '10:00', '11:00']]);
    $this->ana = $this->world->teacher('Ana Ruiz');
    $this->world->rank($this->group, [$this->ana]);
    $this->other = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->world->department->id])->create();
    $this->query = app(TeacherSearchQuery::class);
    $this->foreign = function (string $name, array $attributes = []) {
        $teacher = Teacher::factory()->adscribedTo($this->other)->create(['name' => $name] + $attributes);
        TeacherPosition::factory()->for($teacher)->tiempoCompleto()->create();

        return $teacher;
    };
});

describe('HC-21: TeacherSearchQuery', function () {
    it('HC-21: no busca con menos de 3 caracteres', function () {
        ($this->foreign)('Héctor Olivas');

        expect($this->query->search($this->group, 'He'))->toBe([])->and($this->query->search($this->group, ' H e '))->toBe([]);
    });

    it('HC-21: halla a docentes de otro departamento sin importar acentos ni mayúsculas y los marca', function () {
        ($this->foreign)('Héctor Olivas');

        $found = $this->query->search($this->group, 'HECTOR');

        expect($found)->toHaveCount(1)
            ->and($found[0]->name)->toBe('Héctor Olivas')
            ->and($found[0]->departments)->toBe(['Ciencias Básicas'])
            ->and($found[0]->otherDepartment)->toBeTrue()
            ->and($found[0]->rankingPlace)->toBeNull()
            ->and($found[0]->clash)->toBeNull();
    });

    it('HC-21: halla por RFC, con el RFC exacto primero', function () {
        $rfc = ($this->foreign)('Zacarías Mora', ['rfc' => 'MOZA800101AB1']);
        ($this->foreign)('Aarón Mora', ['rfc' => 'XXXX800101AB1']);

        expect(collect($this->query->search($this->group, 'moza800101ab1'))->pluck('name')->all())->toBe(['Zacarías Mora'])
            ->and(collect($this->query->search($this->group, 'mora'))->pluck('name')->all())->toBe(['Aarón Mora', 'Zacarías Mora']);
        expect($rfc)->not->toBeNull();
    });

    it('HC-21: excluye a los inactivos y a los borrados', function () {
        ($this->foreign)('Inés Inactiva', ['is_active' => false]);
        ($this->foreign)('Inés Borrada')->delete();

        expect($this->query->search($this->group, 'Inés'))->toBe([]);
    });

    it('HC-21: de los del departamento del grupo no es otro departamento y trae su lugar en el ranking', function () {
        $found = $this->query->search($this->group, 'Ruiz');

        expect($found[0]->otherDepartment)->toBeFalse()->and($found[0]->rankingPlace)->toBe(1);
    });

    it('HC-21: devuelve a lo más 10', function () {
        foreach (range(1, 12) as $i) {
            ($this->foreign)("Docente Prueba {$i}");
        }

        expect($this->query->search($this->group, 'Prueba'))->toHaveCount(10);
    });

    it('HC-21: marca el choque con el horario del grupo y no muestra al docente que ya lo tiene', function () {
        $busy = ($this->foreign)('Carla Ocupada');
        $theirs = $this->world->group($this->world->subject('SCC-2000', 'Otra'), $this->isc, 'E', 2, [[1, '10:00', '11:00']]);
        $this->world->assign($theirs, $busy, $this->jefe, position: 1, size: 1);

        $found = $this->query->search($this->group, 'Ocupada');

        expect($found[0]->clash)->toContain('lunes')->and($found[0]->clash)->toContain('10:00');

        $free = ($this->foreign)('Dora Libre');
        $this->world->assign($this->group, $free, $this->jefe, position: 2, size: 2, reason: 'Pruebas');
        expect($this->query->search($this->group->fresh(), 'Libre'))->toBe([]);
    });
});

describe('HC-21: componente y página', function () {
    function searchAssignPage(User $user, array $query = [])
    {
        onCampusPage(test()->world->campus);

        return Livewire::withQueryParams($query)->actingAs($user)->test('staffing::pages.assign');
    }

    it('HC-21: sin 3 caracteres no hay lista; con ellos, resultados o «Sin resultados»', function () {
        ($this->foreign)('Héctor Olivas');

        Livewire::actingAs($this->jefe)->test('staffing::components.teacher-search', ['groupId' => $this->group->id])
            ->assertSee('Mínimo 3 caracteres')
            ->set('term', 'he')
            ->assertDontSeeHtml('data-testid="teacher-search-results"')
            ->set('term', 'hec')
            ->assertSee('Héctor Olivas')
            ->assertSee('Otro departamento')
            ->assertSee('Libre en el horario')
            ->set('term', 'zzzz')
            ->assertSee('Sin resultados');
    });

    it('HC-21: elegir avisa con el grupo y el docente', function () {
        $teacher = ($this->foreign)('Héctor Olivas');

        Livewire::actingAs($this->jefe)->test('staffing::components.teacher-search', ['groupId' => $this->group->id])
            ->set('term', 'hec')
            ->call('pick', $teacher->id)
            ->assertDispatched('teacher-picked', groupId: $this->group->id, teacherId: $teacher->id);
    });

    it('HC-21 y HC-16: asignar a un docente buscado pide el motivo y lo guarda fuera del ranking', function () {
        $teacher = ($this->foreign)('Héctor Olivas');

        $page = searchAssignPage($this->jefe)
            ->call('toggleRanking', $this->group->id)
            ->assertSeeHtml('data-testid="teacher-search"')
            ->dispatch('teacher-picked', groupId: $this->group->id, teacherId: $teacher->id)
            ->assertSeeHtml('data-testid="reason-box"')
            ->assertSee('No está en el ranking del grupo.');

        expect($this->group->fresh()->teacher_id)->toBeNull();

        $page->call('confirmAssign')->assertHasErrors(['reason']);
        $page->set('reason', 'Es el único con experiencia en el tema.')->call('confirmAssign')->assertHasNoErrors();

        $assignment = GroupTeacherAssignment::query()->current()->where('group_id', $this->group->id)->sole();
        expect($this->group->fresh()->teacher_id)->toBe($teacher->id)
            ->and($assignment->ranking_position_at_assignment)->toBeNull()
            ->and($assignment->reason)->toBe('Es el único con experiencia en el tema.');
    });

    it('HC-21: un coordinador y un docente no buscan ni asignan', function (Role $role) {
        $user = $role === Role::Coordinador
            ? User::factory()->withRole($role, ['campus_id' => $this->world->campus->id, 'career_id' => $this->isc->id])->create()
            : User::factory()->withRole($role)->create();
        $teacher = ($this->foreign)('Héctor Olivas');

        Livewire::actingAs($user)->test('staffing::components.teacher-search', ['groupId' => $this->group->id])->assertForbidden();
        searchAssignPage($this->jefe)->dispatch('teacher-picked', groupId: $this->group->id, teacherId: $teacher->id);
        expect($this->group->fresh()->teacher_id)->toBeNull();
    })->with([
        'coordinador' => [Role::Coordinador],
        'docente' => [Role::Docente],
    ]);

    it('HC-21: el jefe de otro departamento no busca para un grupo ajeno', function () {
        $jefeCb = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->other->id])->create();

        Livewire::actingAs($jefeCb)->test('staffing::components.teacher-search', ['groupId' => $this->group->id])->assertForbidden();
    });
});
