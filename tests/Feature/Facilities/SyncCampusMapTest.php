<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Campus;
use App\Modules\Facilities\Actions\SyncCampusMap;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;

/*
| SyncCampusMap (1.24.2): normaliza edificios, salones y departamentos con el mapa del Campus I. Decisiones del usuario
| del 20 de noviembre de 2026: D1 (Campus II por letra), D2 (salones del mapa se crean, los del SII sin lugar se dejan),
| D3 (departamentos: contacto del mapa, clave y nombre del SII).
*/

function miniMap(): array
{
    return [
        'buildings' => [
            ['map_key' => 'Edificio Sistemas', 'code' => 'SC', 'name' => 'Edificio Sistemas'],
            ['map_key' => 'Ciencias basicas', 'code' => 'CB', 'name' => 'Ciencias Básicas'],
            ['map_key' => 'Edificio Guillot', 'code' => 'G', 'name' => 'Edificio Guillot'],
        ],
        'rooms' => [
            ['map_key' => 'Edificio Sistemas|701', 'building' => 'SC', 'code' => '701', 'name' => null, 'type' => 'aula'],
            ['map_key' => 'Edificio Sistemas|710', 'building' => 'SC', 'code' => '710', 'name' => null, 'type' => 'aula'],
            ['map_key' => 'Ciencias basicas|Laboratorio de ciencias básicas', 'building' => 'CB', 'code' => 'CB-LCB', 'name' => 'Laboratorio de ciencias básicas', 'type' => 'laboratorio'],
            ['map_key' => 'Edificio Guillot|Laboratorio de Alta Tecnología (LAT)', 'building' => 'G', 'code' => 'G-LATL', 'name' => 'Laboratorio de Alta Tecnología (LAT)', 'type' => 'laboratorio'],
        ],
        'aliases' => [
            'LABCB' => 'Ciencias basicas|Laboratorio de ciencias básicas',
            'LAT' => 'Edificio Guillot|Laboratorio de Alta Tecnología (LAT)',
        ],
        'departments' => [
            ['code' => '110600', 'map_key' => 'Edificio Sistemas|Departamento Sistemas', 'location' => 'Edificio Sistemas · Departamento Sistemas', 'phone' => '(656) 688-2500 Ext. 2461', 'email' => 'jefatura_syc@cdjuarez.tecnm.mx', 'website' => null],
        ],
    ];
}

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->sync = fn (bool $dry = false) => app(SyncCampusMap::class)->handle($dry, miniMap());
});

function oldBuilding(Campus $campus, string $code): Building
{
    return Building::factory()->create(['campus_id' => $campus->id, 'code' => $code, 'name' => $code]);
}

function oldRoom(Building $building, string $code, array $overrides = []): Room
{
    return Room::factory()->create(['building_id' => $building->id, 'code' => $code, ...$overrides]);
}

it('cruza por número: el salón del SII pasa al edificio del mapa y conserva tipo, capacidad y dueño', function () {
    $department = Department::factory()->create();
    $room = oldRoom(oldBuilding($this->c1, 'U'), '701', ['capacity' => 44, 'department_id' => $department->id]);

    ($this->sync)();

    $room->refresh();
    expect($room->building->code)->toBe('SC')->and($room->building->name)->toBe('Edificio Sistemas')
        ->and($room->map_key)->toBe('Edificio Sistemas|701')
        ->and($room->capacity)->toBe(44)->and($room->department_id)->toBe($department->id);
});

it('cruza por alias: LABCB recibe el nombre del mapa y conserva su clave del SII', function () {
    $room = oldRoom(oldBuilding($this->c1, 'CIENCIASBÁSICAS'), 'LABCB', ['type' => RoomType::Laboratorio]);

    $report = ($this->sync)();

    $room->refresh();
    expect($room->code)->toBe('LABCB')->and($room->name)->toBe('Laboratorio de ciencias básicas')
        ->and($room->building->code)->toBe('CB')->and($room->map_key)->toBe('Ciencias basicas|Laboratorio de ciencias básicas')
        ->and($report['rooms_moved'])->toBeGreaterThan(0)
        ->and(Room::query()->where('code', 'CB-LCB')->exists())->toBeFalse();
});

it('crea los espacios del mapa que no tienen salón, sin dueño y con la capacidad por omisión (supuesto por confirmar)', function () {
    ($this->sync)();

    $lab = Room::query()->where('code', 'G-LATL')->firstOrFail();
    $aula = Room::query()->where('code', '710')->firstOrFail();

    expect($lab->capacity)->toBe(20)->and($lab->type)->toBe(RoomType::Laboratorio)->and($lab->department_id)->toBeNull()
        ->and($aula->capacity)->toBe(30)->and($aula->type)->toBe(RoomType::Aula)->and($aula->building->code)->toBe('SC')
        ->and($aula->campus_id)->toBe($this->c1->id);
});

it('deja intacto el salón del SII que no tiene lugar en el mapa', function () {
    $department = Department::factory()->create();
    $room = oldRoom(oldBuilding($this->c1, 'O'), '501', ['capacity' => 33, 'department_id' => $department->id]);
    $before = $room->only(['building_id', 'capacity', 'department_id', 'name', 'is_active', 'map_key']);

    $report = ($this->sync)();

    expect($room->fresh()->only(['building_id', 'capacity', 'department_id', 'name', 'is_active', 'map_key']))->toBe($before)
        ->and($report['rooms_unmatched'])->toBe(['501'])
        ->and($room->building->fresh()->is_active)->toBeTrue();
});

it('desactiva, sin borrar, el edificio viejo que se quedó sin salones activos', function () {
    $empty = oldBuilding($this->c1, 'U');
    oldRoom($empty, '701');
    $stale = oldBuilding($this->c1, 'X');
    oldRoom($stale, 'OLD', ['is_active' => false]);

    $report = ($this->sync)();

    expect($empty->fresh())->is_active->toBeFalse()->deleted_at->toBeNull()
        ->and($stale->fresh()->is_active)->toBeFalse()
        ->and($report['buildings_deactivated'])->toBe(2);
});

it('reparte Campus II por la letra inicial del salón y deja los demás en CAMPUSII (supuesto por confirmar)', function () {
    $campusII = oldBuilding($this->c2, 'CAMPUSII');
    foreach (['A2', 'B9', 'B10', 'AUDA', 'X', '7'] as $code) {
        oldRoom($campusII, $code);
    }

    $report = ($this->sync)();

    $where = fn (string $code): string => Room::query()->where('code', $code)->firstOrFail()->building->code;
    expect($where('A2'))->toBe('A')->and($where('B9'))->toBe('B')->and($where('B10'))->toBe('B')
        ->and($where('AUDA'))->toBe('CAMPUSII')->and($where('X'))->toBe('CAMPUSII')->and($where('7'))->toBe('CAMPUSII')
        ->and(Building::query()->where('campus_id', $this->c2->id)->where('code', 'A')->value('name'))->toBe('Edificio A')
        ->and($report['c2_rooms_moved'])->toBe(3)->and($report['c2_buildings_created'])->toBe(2);
});

it('actualiza el contacto del departamento del mapa sin tocar su clave ni su nombre', function () {
    $department = Department::factory()->create(['code' => '110600', 'name' => 'Sistemas y Computación']);

    $report = ($this->sync)();

    $department->refresh();
    expect($department->name)->toBe('Sistemas y Computación')->and($department->code)->toBe('110600')
        ->and($department->location)->toBe('Edificio Sistemas · Departamento Sistemas')
        ->and($department->phone)->toBe('(656) 688-2500 Ext. 2461')->and($department->email)->toBe('jefatura_syc@cdjuarez.tecnm.mx')
        ->and($department->website)->toBeNull()->and($department->map_key)->toBe('Edificio Sistemas|Departamento Sistemas')
        ->and($report['departments_updated'])->toBe(1);
});

it('es idempotente: la segunda corrida no cambia nada', function () {
    Department::factory()->create(['code' => '110600']);
    oldRoom(oldBuilding($this->c1, 'U'), '701');
    oldRoom(oldBuilding($this->c1, 'CIENCIASBÁSICAS'), 'LABCB');
    oldRoom(oldBuilding($this->c2, 'CAMPUSII'), 'A2');
    ($this->sync)();
    $snapshot = fn (): array => [Building::withTrashed()->orderBy('campus_id')->orderBy('code')->get()->toArray(), Room::withTrashed()->orderBy('code')->get()->toArray(), Department::query()->get()->toArray()];
    $first = $snapshot();

    $report = ($this->sync)();

    expect($snapshot())->toEqual($first)
        ->and(array_filter($report, fn (mixed $value, string $key): bool => $key !== 'rooms_unmatched' && $value !== 0, ARRAY_FILTER_USE_BOTH))->toBe([]);
});

it('con --dry-run imprime el resumen y no escribe nada', function () {
    $room = oldRoom(oldBuilding($this->c1, 'U'), '701');
    $buildings = Building::count();

    $report = ($this->sync)(true);

    expect($report['rooms_moved'])->toBe(1)->and($report['buildings_created'])->toBe(3)
        ->and(Building::count())->toBe($buildings)->and($room->fresh()->map_key)->toBeNull()
        ->and(Room::query()->whereNotNull('map_key')->count())->toBe(0);

    $this->artisan('facilities:sync-campus-map', ['--dry-run' => true])->assertSuccessful();
    expect(Room::query()->whereNotNull('map_key')->count())->toBe(0);
});

it('el dataset versionado del mapa trae los 25 edificios del Campus I y los 6 departamentos', function () {
    $map = require SyncCampusMap::MAP_PATH;

    expect($map['buildings'])->toHaveCount(25)->and($map['departments'])->toHaveCount(6)
        ->and(collect($map['buildings'])->pluck('code'))->toHaveCount(25)->unique()->toHaveCount(25)
        ->and(collect($map['rooms'])->pluck('map_key')->unique())->toHaveCount(count($map['rooms']))
        ->and(collect($map['rooms'])->pluck('code')->unique())->toHaveCount(count($map['rooms']))
        ->and(array_values($map['aliases']))->each->toBeIn(collect($map['rooms'])->pluck('map_key')->all());
});
