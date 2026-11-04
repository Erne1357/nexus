<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\OrgChart;
use App\Modules\Core\Queries\OrgChartNode;
use App\Modules\Core\Queries\OrgChartQuery;
use App\Modules\Core\Queries\UnitAdscriptions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| OrgChartQuery arma el Organigrama (3.P7, maqueta B): el árbol de unidades con su titular y cuántas personas cuelgan
| de cada una, el detalle de la unidad elegida y las madres posibles al dar de alta o mover. Lee la base al momento,
| sin OrgTree, y arma el diagrama con las mismas consultas tenga las unidades que tenga.
*/

beforeEach(function () {
    $this->travelTo('2026-11-03 10:00:00');

    $this->root = OrgUnit::where('code', 'DIRECCION')->sole();
    $this->academic = OrgUnit::where('code', 'SUB-ACADEMICA')->sole();
    $this->planning = OrgUnit::factory()->create(['code' => 'SUB-PLANEACION', 'name' => 'Subdirección de Planeación y Vinculación', 'kind' => OrgUnitKind::Subdireccion, 'sort' => 1]);
    $this->services = OrgUnit::factory()->create(['parent_id' => $this->planning->id, 'code' => 'SERVICIOS-ESCOLARES', 'name' => 'Departamento de Servicios Escolares']);
    $this->query = app(OrgChartQuery::class);
});

/**
 * Las claves de una lista de nodos, en su orden.
 *
 * @param  list<OrgChartNode>  $nodes
 * @return list<string>
 */
function chartCodes(array $nodes): array
{
    return array_map(fn (OrgChartNode $node): string => $node->code, $nodes);
}

/**
 * El nodo con esa clave, buscado en todo el diagrama; nulo si no está.
 */
function chartNode(OrgChart $chart, string $code): ?OrgChartNode
{
    $pending = $chart->roots;

    while ($pending !== []) {
        $node = array_shift($pending);

        if ($node->code === $code) {
            return $node;
        }

        array_push($pending, ...$node->children);
    }

    return null;
}

it('arma el árbol con las hijas por orden y nombre, sin borradas, y sin inactivas salvo que se pidan', function () {
    OrgUnit::factory()->create(['code' => 'SUB-ADMINISTRATIVA', 'name' => 'Subdirección de Servicios Administrativos', 'kind' => OrgUnitKind::Subdireccion, 'sort' => 2]);
    OrgUnit::factory()->create(['parent_id' => $this->planning->id, 'code' => 'VINCULACION', 'name' => 'Departamento de Gestión Tecnológica y Vinculación']);
    OrgUnit::factory()->create(['parent_id' => $this->planning->id, 'code' => 'EXTRAESCOLARES', 'name' => 'Departamento de Actividades Extraescolares', 'sort' => 1]);
    OrgUnit::factory()->create(['parent_id' => $this->planning->id, 'code' => 'BORRADA', 'name' => 'A borrada'])->delete();
    OrgUnit::factory()->inactive()->create(['parent_id' => $this->planning->id, 'code' => 'BORRADA-INACTIVA', 'name' => 'A borrada inactiva'])->delete();
    $grants = OrgUnit::factory()->inactive()->create(['parent_id' => $this->planning->id, 'code' => 'BECAS', 'name' => 'Oficina de Becas', 'kind' => OrgUnitKind::Oficina, 'sort' => 1]);
    OrgUnit::factory()->create(['parent_id' => $grants->id, 'code' => 'BECAS-EXT', 'name' => 'Becas externas', 'kind' => OrgUnitKind::Oficina]);
    Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);

    $chart = $this->query->chart();

    expect(chartCodes($chart->roots))->toBe(['DIRECCION'])
        ->and(chartCodes($chart->roots[0]->children))->toBe(['SUB-ACADEMICA', 'SUB-PLANEACION', 'SUB-ADMINISTRATIVA'])
        ->and(chartCodes(chartNode($chart, 'SUB-PLANEACION')->children))->toBe(['VINCULACION', 'SERVICIOS-ESCOLARES', 'EXTRAESCOLARES'])
        ->and(chartNode($chart, 'BECAS'))->toBeNull()
        ->and(chartNode($chart, 'BECAS-EXT'))->toBeNull()
        ->and($chart->inactive)->toBe(1)
        ->and(chartNode($chart, 'SC'))->kind->toBe(OrgUnitKind::DepartamentoAcademico)->locked->toBeTrue()->active->toBeTrue()
        ->and(chartNode($chart, 'SUB-PLANEACION'))->locked->toBeFalse()->name->toBe('Subdirección de Planeación y Vinculación');

    $all = $this->query->chart(withInactive: true);

    expect(chartCodes(chartNode($all, 'SUB-PLANEACION')->children))->toBe(['VINCULACION', 'SERVICIOS-ESCOLARES', 'EXTRAESCOLARES', 'BECAS'])
        ->and(chartNode($all, 'BECAS'))->active->toBeFalse()
        ->and(chartCodes(chartNode($all, 'BECAS')->children))->toBe(['BECAS-EXT'])
        ->and(chartNode($all, 'BORRADA'))->toBeNull()
        ->and($all->inactive)->toBe(1)
        ->and(chartNode($all, 'SC')->search())->toBe('sc sistemas y computacion');
});

it('da el titular de cada departamento académico y de Subdirección Académica, y cuenta los departamentos sin jefe', function () {
    $c2 = Campus::factory()->create(['code' => 'C2']);
    $basics = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $electric = Department::factory()->create(['code' => 'EE', 'name' => 'Eléctrica y Electrónica']);
    $metal = Department::factory()->create(['code' => 'MM', 'name' => 'Metal Mecánica']);
    Department::factory()->inactive()->create(['code' => 'II', 'name' => 'Ingeniería Industrial']);

    User::factory()->withRole(Role::Subdireccion)->create(['name' => 'Alejandro Rentería Cota']);
    User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => $basics->id])->create(['name' => 'Elena Gutiérrez Parra']);
    User::factory()->withRole(Role::JefeProyectoDocente, ['org_unit_id' => $electric->id, 'campus_id' => $c2->id])->create(['name' => 'Jefa de proyecto']);
    User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => $metal->id, 'valid_from' => '2025-01-01', 'valid_to' => '2026-11-02'])->create(['name' => 'Jefe anterior']);

    $chart = $this->query->chart(withInactive: true);

    expect(chartNode($chart, 'SUB-ACADEMICA'))->titular->toBe('Alejandro Rentería Cota')->missingTitular->toBeFalse()
        ->and(chartNode($chart, 'CB'))->titular->toBe('Elena Gutiérrez Parra')->missingTitular->toBeFalse()
        ->and(chartNode($chart, 'EE'))->titular->toBeNull()->missingTitular->toBeTrue()
        ->and(chartNode($chart, 'MM'))->titular->toBeNull()->missingTitular->toBeTrue()
        ->and(chartNode($chart, 'II'))->titular->toBeNull()->missingTitular->toBeFalse()
        ->and(chartNode($chart, 'SUB-PLANEACION'))->titular->toBeNull()->missingTitular->toBeFalse()
        ->and(chartNode($chart, 'DIRECCION'))->titular->toBeNull()->missingTitular->toBeFalse()
        ->and($chart->headless)->toBe(2);

    vacate(Role::Subdireccion);

    expect(chartNode($this->query->chart(), 'SUB-ACADEMICA'))->titular->toBeNull()->missingTitular->toBeTrue()
        ->and($this->query->chart()->headless)->toBe(2);
});

it('cuenta personas distintas con adscripción o puesto vigente en el subárbol', function () {
    $c2 = Campus::factory()->create(['code' => 'C2']);
    $office = OrgUnit::factory()->create(['parent_id' => $this->services->id, 'code' => 'OF-TITULOS', 'kind' => OrgUnitKind::Oficina]);
    $systems = Department::factory()->create(['code' => 'SC']);
    [$ana, $beto, $cris, $dora, $eva, $fede, $gil] = User::factory()->count(7)->create()->all();

    app()->instance(UnitAdscriptions::class, new readonly class([$systems->id => [$ana->id, $beto->id], $office->id => [$cris->id], $this->services->id => [$ana->id]]) implements UnitAdscriptions
    {
        /** @param  array<string, list<string>>  $byUnit */
        public function __construct(private array $byUnit) {}

        public function userIdsByUnit(): array
        {
            return $this->byUnit;
        }
    });

    RoleAssignment::factory()->for($dora)->create(['role' => Role::JefeProyectoDocente, 'org_unit_id' => $systems->id, 'campus_id' => $c2->id]);
    RoleAssignment::factory()->for($beto)->create(['role' => Role::JefeDepartamento, 'org_unit_id' => $systems->id]);
    RoleAssignment::factory()->for($eva)->create(['role' => Role::Subdireccion, 'org_unit_id' => $this->academic->id]);
    RoleAssignment::factory()->for($fede)->create(['role' => Role::JefeProyectoDocente, 'org_unit_id' => $systems->id, 'campus_id' => $c2->id, 'valid_from' => '2025-01-01', 'valid_to' => '2026-11-02']);
    RoleAssignment::factory()->for($gil)->create(['role' => Role::JefeProyectoDocente, 'org_unit_id' => $systems->id, 'campus_id' => $c2->id, 'valid_from' => '2026-11-04']);

    $chart = app(OrgChartQuery::class)->chart();

    expect(chartNode($chart, 'SC')->people)->toBe(3)
        ->and(chartNode($chart, 'SUB-ACADEMICA')->people)->toBe(4)
        ->and(chartNode($chart, 'OF-TITULOS')->people)->toBe(1)
        ->and(chartNode($chart, 'SERVICIOS-ESCOLARES')->people)->toBe(2)
        ->and(chartNode($chart, 'SUB-PLANEACION')->people)->toBe(2)
        ->and(chartNode($chart, 'DIRECCION')->people)->toBe(5)
        ->and(app(OrgChartQuery::class)->detail($this->academic->id)->people)->toBe(4);
});

it('arma el diagrama con cuatro consultas, tenga las unidades que tenga', function () {
    $query = app(OrgChartQuery::class);
    $count = function () use ($query): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $query->chart(withInactive: true);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    OrgUnit::query()->whereKey($this->services->id)->forceDelete();
    expect(OrgUnit::count())->toBe(3)
        ->and($count())->toBe(4);

    foreach (range(1, 6) as $number) {
        $sub = OrgUnit::factory()->create(['code' => "SUB-{$number}", 'kind' => OrgUnitKind::Subdireccion]);
        OrgUnit::factory()->create(['parent_id' => $sub->id, 'code' => "OF-{$number}", 'kind' => OrgUnitKind::Oficina]);
    }

    foreach (range(1, 12) as $number) {
        $department = Department::factory()->create(['code' => "D{$number}"]);
        User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => $department->id])->create();
    }

    OrgUnit::factory()->count(13)->create(['parent_id' => $this->planning->id]);
    User::factory()->withRole(Role::Subdireccion)->create();

    expect(OrgUnit::count())->toBe(40)
        ->and($count())->toBe(4);
});

it('detail trae la ruta, los puestos de la unidad, sus hijas y los motivos de bloqueo', function () {
    $division = OrgUnit::factory()->create(['parent_id' => $this->academic->id, 'code' => 'DIV-PROFESIONAL', 'name' => 'División de Estudios Profesionales', 'kind' => OrgUnitKind::Division, 'sort' => 1]);
    OrgUnit::factory()->create(['parent_id' => $this->academic->id, 'code' => 'BORRADA'])->delete();
    $systems = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $alejandro = User::factory()->withRole(Role::Subdireccion, ['valid_from' => '2025-09-01', 'valid_to' => '2026-12-31'])->create(['name' => 'Alejandro Rentería Cota']);
    $berta = User::factory()->withRole(Role::Subdireccion, ['valid_from' => '2027-01-01'])->create(['name' => 'Berta Salas']);
    User::factory()->withRole(Role::Subdireccion, ['valid_from' => '2024-01-01', 'valid_to' => '2025-08-31'])->create(['name' => 'Carla Antes']);

    $detail = $this->query->detail($this->academic->id);

    expect($detail->unit->is($this->academic))->toBeTrue()
        ->and($detail->path)->toBe(['Dirección'])
        ->and($detail->titular)->toEqual(['name' => 'Alejandro Rentería Cota', 'userId' => $alejandro->id, 'summary' => 'Subdirección Académica', 'from' => CarbonImmutable::parse('2025-09-01')])
        ->and($detail->titularNote)->toBeNull()
        ->and(array_column($detail->assignments, 'name'))->toBe(['Alejandro Rentería Cota', 'Berta Salas'])
        ->and(array_column($detail->assignments, 'userId'))->toBe([$alejandro->id, $berta->id])
        ->and(array_map(fn (array $row): string => $row['status']->value, $detail->assignments))->toBe(['vigente', 'programado'])
        ->and($detail->assignments[1]['summary'])->toBe('Subdirección Académica')
        ->and($detail->assignments[1]['from']->toDateString())->toBe('2027-01-01')
        ->and(array_map(fn (OrgUnit $unit): string => $unit->code, $detail->children))->toBe(['SC', 'DIV-PROFESIONAL'])
        ->and($detail->lockedReason)->toBeNull()
        ->and($detail->moveBlocker)->toBeNull()
        ->and($detail->deactivationBlockers)->toBe([
            'Tiene 2 unidades hijas activas; muévelas o desactívalas primero.',
            'Tiene 2 nombramientos vigentes o por empezar; termínalos en Personas y puestos.',
        ])
        ->and($detail->activationBlocker)->toBeNull();

    $root = $this->query->detail($this->root->id);

    expect($root->path)->toBe([])
        ->and($root->titular)->toBeNull()
        ->and($root->titularNote)->toBe('La Dirección no tiene un puesto en Nexus.')
        ->and($root->moveBlocker)->toBe('La Dirección es la raíz: no se mueve.')
        ->and($root->deactivationBlockers[0])->toBe('La Dirección es la raíz: no se desactiva.');

    $department = $this->query->detail($systems->id);

    expect($department->path)->toBe(['Dirección', 'Subdirección Académica'])
        ->and($department->titularNote)->toBe('Sin jefe vigente. El jefe se nombra en la ficha del docente.')
        ->and($department->lockedReason)->toBe('Es un departamento académico: su nombre, su clave y su estado se editan en Académico › Departamentos.')
        ->and($department->moveBlocker)->toBe('Un departamento académico no se mueve desde el Organigrama.');

    expect($this->query->detail($this->services->id))
        ->path->toBe(['Dirección', 'Subdirección de Planeación y Vinculación'])
        ->titularNote->toBe('Esta unidad todavía no tiene un puesto de titular en Nexus.')
        ->assignments->toBe([])
        ->deactivationBlockers->toBe([]);

    $this->planning->update(['is_active' => false]);
    $this->services->update(['is_active' => false]);

    expect($this->query->detail($this->services->id)->activationBlocker)->toBe('Activa primero Subdirección de Planeación y Vinculación.')
        ->and($this->query->detail($division->id)->titularNote)->toBe('Esta unidad todavía no tiene un puesto de titular en Nexus.');

    RoleAssignment::query()->where('role', Role::Subdireccion)->delete();

    expect($this->query->detail($this->academic->id)->titularNote)->toBe('Sin titular vigente.')
        ->and(fn () => $this->query->detail('no-es-uuid'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $this->query->detail(OrgUnit::withTrashed()->where('code', 'BORRADA')->value('id')))->toThrow(ModelNotFoundException::class);
});

it('parentOptions deja fuera el subárbol de la unidad que se mueve y los departamentos académicos', function () {
    $office = OrgUnit::factory()->create(['parent_id' => $this->services->id, 'code' => 'OF-TITULOS', 'name' => 'Oficina de Títulos', 'kind' => OrgUnitKind::Oficina]);
    $administrative = OrgUnit::factory()->create(['code' => 'SUB-ADMINISTRATIVA', 'name' => 'Subdirección de Servicios Administrativos', 'kind' => OrgUnitKind::Subdireccion, 'sort' => 2]);
    OrgUnit::factory()->inactive()->create(['parent_id' => $administrative->id, 'code' => 'INACTIVA', 'name' => 'Oficina inactiva']);
    Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $indent = "\u{00A0}\u{00A0}\u{00A0}";

    expect($this->query->parentOptions())->toBe([
        ['id' => $this->root->id, 'name' => 'Dirección'],
        ['id' => $this->academic->id, 'name' => '└ Subdirección Académica'],
        ['id' => $this->planning->id, 'name' => '└ Subdirección de Planeación y Vinculación'],
        ['id' => $this->services->id, 'name' => "{$indent}└ Departamento de Servicios Escolares"],
        ['id' => $office->id, 'name' => "{$indent}{$indent}└ Oficina de Títulos"],
        ['id' => $administrative->id, 'name' => '└ Subdirección de Servicios Administrativos'],
    ])->and($this->query->parentOptions($this->planning))->toBe([
        ['id' => $this->root->id, 'name' => 'Dirección'],
        ['id' => $this->academic->id, 'name' => '└ Subdirección Académica'],
        ['id' => $administrative->id, 'name' => '└ Subdirección de Servicios Administrativos'],
    ]);
});

it('la unidad huérfana de un departamento aparece con candado bajo su madre', function () {
    $id = (string) Str::uuid7();
    DB::table('org_units')->insert([
        'id' => $id,
        'parent_id' => $this->academic->id,
        'code' => 'HUERFANO',
        'name' => 'Departamento sin departamento',
        'kind' => OrgUnitKind::DepartamentoAcademico->value,
        'sort' => 0,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $chart = $this->query->chart();

    expect(chartCodes(chartNode($chart, 'SUB-ACADEMICA')->children))->toBe(['HUERFANO'])
        ->and(chartNode($chart, 'HUERFANO'))->locked->toBeTrue()->missingTitular->toBeTrue()
        ->and($chart->headless)->toBe(1)
        ->and($this->query->detail($id)->lockedReason)->not->toBeNull();
});
