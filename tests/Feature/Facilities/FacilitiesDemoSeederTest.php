<?php

use App\Modules\Academic\Database\Seeders\AcademicDemoSeeder;
use App\Modules\Core\Database\Seeders\CampusSeeder;
use App\Modules\Core\Database\Seeders\DemoTermSeeder;
use App\Modules\Core\Models\Campus;
use App\Modules\Facilities\Database\Seeders\FacilitiesDemoSeeder;
use App\Modules\Facilities\Enums\RoomDefaultSource;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Models\RoomBlock;
use App\Modules\Facilities\Models\SubjectRoomDefault;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;

/*
| Edificios, salones, bloqueos y salones por defecto demo (tarea 7a del carril C): se siembran sobre el catálogo
| demo, por clave natural, con una semilla. Cubren los cinco tipos, con y sin departamento dueño, inactivos y
| borrados, capacidades de 12 a 300 y las materias con laboratorio.
*/

/**
 * Siembra el catálogo de `$campuses` y las instalaciones demo.
 *
 * @param  list<string>  $campuses
 */
function seedDemoFacilities(array $campuses = ['C2'], int $seed = 2027): void
{
    $seeder = (new DatabaseSeeder)->setContainer(app());

    $seeder->call(CampusSeeder::class);
    $seeder->call(DemoTermSeeder::class);
    $seeder->callWith(AcademicDemoSeeder::class, ['campuses' => $campuses]);
    $seeder->callWith(FacilitiesDemoSeeder::class, ['campuses' => $campuses, 'seed' => $seed]);
}

function campusId(string $code): string
{
    return Campus::query()->where('code', $code)->value('id');
}

/**
 * Cuenta de lo sembrado, contando lo borrado.
 *
 * @return array<string, int>
 */
function facilitiesCounts(): array
{
    return [
        'buildings' => Building::withTrashed()->count(),
        'rooms' => Room::withTrashed()->count(),
        'blocks' => RoomBlock::withTrashed()->count(),
        'defaults' => SubjectRoomDefault::withTrashed()->count(),
    ];
}

it('siembra los edificios y salones de Campus II en el orden de magnitud previsto', function () {
    seedDemoFacilities();

    $campus = campusId('C2');

    expect(Building::withTrashed()->where('campus_id', $campus)->count())->toBeBetween(6, 8)
        ->and(Room::withTrashed()->where('campus_id', $campus)->count())->toBeBetween(80, 100)
        ->and(Building::withTrashed()->where('campus_id', '!=', $campus)->count())->toBe(0);
});

it('siembra Campus I con más edificios y salones', function () {
    seedDemoFacilities(['C1', 'C2']);

    expect(Building::withTrashed()->where('campus_id', campusId('C1'))->count())->toBeBetween(11, 13)
        ->and(Room::withTrashed()->where('campus_id', campusId('C1'))->count())->toBeBetween(165, 200);
});

it('cubre los cinco tipos de salón, con y sin departamento dueño, en cada campus', function () {
    seedDemoFacilities(['C1', 'C2']);

    foreach (['C1', 'C2'] as $code) {
        $rooms = Room::query()->where('campus_id', campusId($code))->get();

        expect($rooms->pluck('type')->unique()->map->value->sort()->values()->all())
            ->toBe(collect(RoomType::cases())->map->value->sort()->values()->all())
            ->and($rooms->whereNotNull('department_id'))->not->toBeEmpty()
            ->and($rooms->whereNull('department_id'))->not->toBeEmpty();
    }
});

it('incluye casos extremos: inactivos, un edificio inactivo, un salón borrado, capacidades de 12 a 300 y nombres largos', function () {
    seedDemoFacilities();

    $campus = campusId('C2');
    $rooms = Room::withTrashed()->where('campus_id', $campus)->get();

    expect(Room::query()->where('campus_id', $campus)->where('is_active', false)->count())->toBeGreaterThan(0)
        ->and(Building::query()->where('campus_id', $campus)->where('is_active', false)->count())->toBeGreaterThan(0)
        ->and(Room::onlyTrashed()->where('campus_id', $campus)->count())->toBeGreaterThan(0)
        ->and($rooms->min('capacity'))->toBe(12)
        ->and($rooms->max('capacity'))->toBe(300)
        ->and($rooms->contains(fn (Room $room): bool => mb_strlen((string) $room->name) >= 60))->toBeTrue()
        ->and($rooms->contains(fn (Room $room): bool => $room->features !== []))->toBeTrue();
});

it('siembra bloqueos de un periodo y de todos, con horas válidas', function () {
    seedDemoFacilities();

    $current = DB::table('terms')->where('code', '20271')->value('id');
    $blocks = RoomBlock::query()->get();

    expect($blocks)->not->toBeEmpty()
        ->and($blocks->whereNull('term_id'))->not->toBeEmpty()
        ->and($blocks->where('term_id', $current))->not->toBeEmpty()
        ->and($blocks->every(fn (RoomBlock $block): bool => $block->day >= 1 && $block->day <= 6 && $block->starts_at < $block->ends_at))->toBeTrue();
});

it('da salones por defecto a las materias con laboratorio, con prioridad 1 y 2 y las dos fuentes', function () {
    seedDemoFacilities();

    $campus = campusId('C2');
    $defaults = SubjectRoomDefault::query()->where('campus_id', $campus)->get();
    $labSubjects = DB::table('subjects')->where('needs_lab', true)->pluck('id');

    expect($defaults)->not->toBeEmpty()
        ->and($defaults->pluck('priority')->unique()->sort()->values()->all())->toBe([1, 2])
        ->and($defaults->pluck('source')->unique()->map->value->sort()->values()->all())->toBe(['historial', 'manual'])
        ->and($defaults->every(fn (SubjectRoomDefault $default): bool => $labSubjects->contains($default->subject_id)))->toBeTrue()
        ->and($defaults->every(fn (SubjectRoomDefault $default): bool => $default->room->campus_id === $campus && $default->room->is_active))->toBeTrue()
        ->and($defaults->first()->source)->toBeInstanceOf(RoomDefaultSource::class);
});

it('volver a sembrar no duplica nada', function () {
    seedDemoFacilities();
    $first = facilitiesCounts();

    seedDemoFacilities();

    expect(facilitiesCounts())->toBe($first);
});

/**
 * Lo sembrado de un campus sin ids, para comparar corridas.
 *
 * @return array<string, mixed>
 */
function facilitiesSnapshot(string $campusCode): array
{
    $campus = campusId($campusCode);
    $roomCodes = DB::table('rooms')->pluck('code', 'id');
    $subjectCodes = DB::table('subjects')->pluck('code', 'id');

    return [
        'buildings' => DB::table('buildings')->where('campus_id', $campus)->orderBy('code')->get(['code', 'name', 'is_active', 'deleted_at'])->map(fn ($row) => ['deleted_at' => $row->deleted_at !== null] + (array) $row)->all(),
        'rooms' => DB::table('rooms')->where('campus_id', $campus)->orderBy('code')->get(['code', 'name', 'type', 'capacity', 'features', 'is_active', 'deleted_at'])->map(fn ($row) => ['deleted_at' => $row->deleted_at !== null] + (array) $row)->all(),
        'blocks' => DB::table('room_blocks')->whereIn('room_id', DB::table('rooms')->where('campus_id', $campus)->select('id'))->get()
            ->map(fn ($row): string => implode('|', [$roomCodes[$row->room_id], $row->term_id === null ? '-' : 'T', $row->day, $row->starts_at, $row->ends_at, $row->reason]))->sort()->values()->all(),
        'defaults' => DB::table('subject_room_defaults')->where('campus_id', $campus)->get()
            ->map(fn ($row): string => implode('|', [$subjectCodes[$row->subject_id], $roomCodes[$row->room_id], $row->priority, $row->source]))->sort()->values()->all(),
    ];
}

function wipeFacilities(): void
{
    DB::table('subject_room_defaults')->delete();
    DB::table('room_blocks')->delete();
    DB::table('rooms')->delete();
    DB::table('buildings')->delete();
}

it('con la misma semilla salen los mismos salones, características, bloqueos y salones por defecto', function () {
    seedDemoFacilities(['C2'], 7);
    $a = facilitiesSnapshot('C2');

    wipeFacilities();
    seedDemoFacilities(['C2'], 7);

    expect(facilitiesSnapshot('C2'))->toBe($a)
        ->and($a['blocks'])->not->toBeEmpty()
        ->and($a['defaults'])->not->toBeEmpty();
});

it('Campus II sale igual sembrado solo o junto con Campus I, y no suma nada al cambiar la lista de campus', function () {
    seedDemoFacilities(['C2']);
    $alone = facilitiesSnapshot('C2');

    wipeFacilities();
    seedDemoFacilities(['C1', 'C2']);

    expect(facilitiesSnapshot('C2'))->toBe($alone);

    $counts = facilitiesCounts();
    seedDemoFacilities(['C2']);

    expect(facilitiesCounts())->toBe($counts);
});

it('se niega a correr en producción', function () {
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => (new FacilitiesDemoSeeder)->run())->toThrow(RuntimeException::class);
});
