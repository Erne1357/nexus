<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\PeopleQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/*
| Lecturas de «Personas y puestos»: la lista de todos los usuarios con sus puestos vigentes, la búsqueda de personas,
| quién ocupa un puesto y las opciones de alcance de cada puesto.
*/

beforeEach(function () {
    $this->travelTo('2026-11-03 10:00:00');

    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->off = Campus::factory()->inactive()->create(['code' => 'EAD', 'name' => 'A Distancia']);
    $this->isc = Career::factory()->create(['code' => 'ISC', 'name' => 'Ingeniería en Sistemas Computacionales']);
    $this->ige = Career::factory()->create(['code' => 'IGE', 'name' => 'Ingeniería en Gestión Empresarial']);
    CareerCampus::factory()->create(['career_id' => $this->isc->id, 'campus_id' => $this->c2->id]);
    CareerCampus::factory()->create(['career_id' => $this->ige->id, 'campus_id' => $this->c2->id]);
    $this->people = app(PeopleQuery::class);
});

it('lista a todos por nombre y busca literal por nombre o usuario', function () {
    User::factory()->create(['name' => 'Zoe Zárate', 'username' => 'zoe']);
    User::factory()->create(['name' => 'Ana 100% Real', 'username' => 'ana_1']);
    User::factory()->create(['name' => 'Beto Bravo', 'username' => 'bb\\x']);
    User::factory()->create(['name' => 'Cris Inactivo', 'username' => 'cris', 'is_active' => false]);

    expect($this->people->forTable()->pluck('name')->all())->toBe(['Ana 100% Real', 'Beto Bravo', 'Cris Inactivo', 'Zoe Zárate'])
        ->and($this->people->forTable('100%')->pluck('name')->all())->toBe(['Ana 100% Real'])
        ->and($this->people->forTable('ana_')->pluck('name')->all())->toBe(['Ana 100% Real'])
        ->and($this->people->forTable('a_1')->pluck('name')->all())->toBe(['Ana 100% Real'])
        ->and($this->people->forTable('\\')->pluck('name')->all())->toBe(['Beto Bravo'])
        ->and($this->people->forTable('zo_')->pluck('name')->all())->toBe([])
        ->and($this->people->forTable('ZOE')->pluck('name')->all())->toBe(['Zoe Zárate']);
});

it('AC-15: sin búsqueda ni puesto no lista a los alumnos que solo son alumnos; salen al buscarlos o con el puesto', function () {
    User::factory()->create(['name' => 'Ana Sin Puesto']);
    User::factory()->withRole(Role::Alumno)->create(['name' => 'Beto Alumno', 'username' => '21110001']);
    User::factory()->withRole(Role::Alumno)->withRole(Role::Docente)->create(['name' => 'Cris Alumna y Docente']);
    User::factory()->withRole(Role::Alumno, ['valid_from' => '2025-01-01', 'valid_to' => '2026-10-31'])->create(['name' => 'Dora Exalumna']);

    expect($this->people->forTable()->pluck('name')->all())->toBe(['Ana Sin Puesto', 'Cris Alumna y Docente', 'Dora Exalumna'])
        ->and($this->people->forTable('21110001')->pluck('name')->all())->toBe(['Beto Alumno'])
        ->and($this->people->forTable('', Role::Alumno)->pluck('name')->all())->toBe(['Beto Alumno', 'Cris Alumna y Docente']);
});

it('filtra por un puesto vigente y trae solo los vigentes', function () {
    $current = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->isc->id])->create(['name' => 'Ana Coordina']);
    User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->ige->id, 'valid_from' => '2025-01-01', 'valid_to' => '2026-10-31'])->create(['name' => 'Beto Terminado']);
    User::factory()->create(['name' => 'Cris Sin Puesto']);
    RoleAssignment::factory()->create(['user_id' => $current->id, 'valid_from' => '2024-01-01', 'valid_to' => '2025-01-01']);

    $page = $this->people->forTable('', Role::Coordinador);

    expect($page->pluck('name')->all())->toBe(['Ana Coordina'])
        ->and($page->first()->roleAssignments->pluck('role')->all())->toBe([Role::Coordinador]);
});

it('holders compara solo las columnas del puesto', function () {
    $pres = modalityOf('PRES')->id;
    $isc = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->isc->id])->create(['name' => 'Ana ISC']);
    User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->ige->id])->create(['name' => 'Beto IGE']);
    User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->isc->id, 'valid_from' => '2025-01-01', 'valid_to' => '2026-01-01'])->create(['name' => 'Cris Viejo']);
    $admin = User::factory()->admin()->create(['name' => 'Ada Admin']);
    $other = User::factory()->admin()->create(['name' => 'Beta Admin']);

    $holders = $this->people->holders(Role::Coordinador, $this->c2->id, null, $this->isc->id, $pres);

    expect($holders->pluck('user.name')->all())->toBe(['Ana ISC'])
        ->and($holders->first()->user_id)->toBe($isc->id)
        ->and($this->people->holders(Role::Coordinador, $this->c2->id, null, null, $pres))->toBeEmpty()
        ->and($this->people->holders(Role::Coordinador, $this->c2->id, null, $this->isc->id))->toBeEmpty()
        ->and($this->people->holders(Role::Admin, null, null, null)->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$admin->id, $other->id])->sort()->values()->all());
});

it('AZ-S17: holders con el campus opcional: sin campus, todos los de la modalidad; con campus, los de ese campus y los de todos los campus', function () {
    $pres = modalityOf('PRES')->id;
    User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->isc->id])->create(['name' => 'Ana C2']);
    User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c1->id, 'career_id' => $this->isc->id])->create(['name' => 'Beto C1']);
    User::factory()->withRole(Role::Coordinador, ['career_id' => $this->isc->id])->create(['name' => 'Cris Todos']);

    expect($this->people->holders(Role::Coordinador, null, null, $this->isc->id, $pres)->pluck('user.name')->sort()->values()->all())->toBe(['Ana C2', 'Beto C1', 'Cris Todos'])
        ->and($this->people->holders(Role::Coordinador, $this->c2->id, null, $this->isc->id, $pres)->pluck('user.name')->sort()->values()->all())->toBe(['Ana C2', 'Cris Todos']);
});

it('scopeOptions: lo que cada puesto ofrece para elegir', function () {
    $academic = app(OrgTree::class)->idOf(OrgTree::ACADEMIC);
    $systems = Department::factory()->create(['name' => 'Sistemas y Computación']);
    Department::factory()->inactive()->create(['name' => 'Metal Mecánica']);
    $oficina = OrgUnit::factory()->create(['name' => 'Oficina X', 'kind' => OrgUnitKind::Oficina]);
    $services = OrgUnit::factory()->create(['code' => OrgTree::SCHOOL_SERVICES, 'name' => 'Departamento de Servicios Escolares', 'kind' => OrgUnitKind::Departamento]);
    $linkage = OrgUnit::factory()->create(['code' => 'VINCULACION', 'name' => 'Departamento de Vinculación', 'kind' => OrgUnitKind::Departamento]);

    $subdireccion = $this->people->scopeOptions(Role::Subdireccion, null);
    $jefe = $this->people->scopeOptions(Role::JefeDepartamento, null);
    $coordinador = $this->people->scopeOptions(Role::Coordinador, $this->c2->id);

    expect($subdireccion['units'])->toBe([['id' => $academic, 'name' => OrgUnit::find($academic)->name]])
        ->and(array_column($jefe['units'], 'id'))->toContain($systems->id, $services->id)->not->toContain($oficina->id)->not->toContain($linkage->id)
        ->and(array_column($this->people->scopeOptions(Role::JefeProyectoDocente, null)['units'], 'id'))->toContain($systems->id)->not->toContain($services->id)
        ->and(array_column($jefe['units'], 'name'))->not->toContain('Metal Mecánica')
        ->and($jefe['careers'])->toBe([])
        ->and($coordinador['units'])->toBe([])
        ->and($coordinador['careers'])->toBe([
            ['id' => $this->ige->id, 'name' => 'IGE · Ingeniería en Gestión Empresarial'],
            ['id' => $this->isc->id, 'name' => 'ISC · Ingeniería en Sistemas Computacionales'],
        ])
        ->and($coordinador['campuses'])->toBe([['id' => $this->c1->id, 'name' => 'C1 · Campus I'], ['id' => $this->c2->id, 'name' => 'C2 · Campus II']])
        ->and($coordinador['modalities'])->toBe([['id' => modalityOf('PRES')->id, 'name' => 'Presencial']]);
});

it('AZ-S17: scopeOptions sin campus da las carreras que se ofrecen en algún campus si el campus es opcional, y ninguna si no hay carrera', function () {
    expect(array_column($this->people->scopeOptions(Role::Coordinador, null)['careers'], 'id'))->toBe([$this->ige->id, $this->isc->id])
        ->and($this->people->scopeOptions(Role::JefeProyectoDocente, null)['careers'])->toBe([])
        ->and($this->people->defaultModalityId())->toBe(modalityOf('PRES')->id);

    modalityOf('EAD')->update(['is_active' => true]);

    expect($this->people->defaultModalityId())->toBeNull()
        ->and(array_column($this->people->modalityOptions(), 'name'))->toBe(['EAD', 'Presencial']);
});

it('busca personas por nombre o usuario, hasta el límite', function () {
    foreach (range(1, 10) as $number) {
        User::factory()->create(['name' => "Rosa {$number}", 'username' => "r{$number}"]);
    }
    User::factory()->create(['name' => 'Otra', 'username' => 'rosita']);

    expect($this->people->search('rosa')->count())->toBe(8)
        ->and($this->people->search('rosita')->pluck('name')->all())->toBe(['Otra'])
        ->and($this->people->search('   ')->all())->toBe([]);
});

it('findOrFail rechaza un id que no es uuid', function () {
    $user = User::factory()->create();

    expect($this->people->findOrFail($user->id)->is($user))->toBeTrue()
        ->and(fn () => $this->people->findOrFail('no-es-uuid'))->toThrow(ModelNotFoundException::class);
});
