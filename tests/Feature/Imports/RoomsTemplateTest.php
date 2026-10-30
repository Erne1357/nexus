<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Imports\Actions\ApplyImportBatch;
use App\Modules\Imports\Actions\StoreImportBatch;
use App\Modules\Imports\Actions\ValidateImportBatch;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Templates\ImportContext;
use App\Modules\Imports\Templates\RoomsTemplate;
use App\Modules\Imports\Templates\TemplateRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
| Plantilla `rooms` (tarea 1.15): los salones del SII de un campus, el del lote. Trae edificio, clave, nombre,
| tipo, capacidad y departamento dueño; la clave natural es la clave del salón dentro del campus. El edificio que
| el campus no tiene se crea con su clave como nombre y avisa (S11, supuesto por confirmar). Como todo lo importado
| no reactiva ni restaura; «desactivar faltantes» solo mira los salones activos del campus del lote.
*/

beforeEach(function () {
    Storage::fake('imports');
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->owner = Department::factory()->create(['code' => 'XA']);
    $this->building = Building::factory()->for($this->c2)->create(['code' => 'EA', 'name' => 'Edificio A']);
    $this->admin = User::factory()->admin()->create();
    $this->context = new ImportContext($this->admin, $this->c2);
    $this->template = new RoomsTemplate;
});

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function importRoomRow(array $changes = []): array
{
    return (new RoomsTemplate)->normalize(['building' => 'EA', 'code' => 'EA-101', 'name' => 'Aula 101', 'type' => 'Aula', 'capacity' => '40', 'department' => 'XA', ...$changes]);
}

function validatedRoomsBatch(User $admin, Campus $campus, string $csv): ImportBatch
{
    $batch = app(StoreImportBatch::class)->handle($admin, 'rooms', UploadedFile::fake()->createWithContent('salones.csv', $csv), $campus);

    return app(ValidateImportBatch::class)->handle($batch);
}

it('está registrada en Imports, es de un campus y ofrece desactivar faltantes', function () {
    expect(app(TemplateRegistry::class)->get('rooms'))
        ->toBeInstanceOf(RoomsTemplate::class)
        ->label()->toBe('Salones')
        ->requiresCampus()->toBeTrue()
        ->deactivatesMissing()->toBeTrue()
        ->and($this->template->naturalKey())->toBe(['code']);
});

it('una fila con edificio, tipo, capacidad y dueño válidos no trae errores ni avisos', function () {
    $issues = $this->template->validate(importRoomRow(), $this->context);

    expect($issues->errors)->toBe([])->and($issues->warnings)->toBe([]);
});

it('tipo desconocido, capacidad cero o no entera y departamento inexistente son error con su mensaje', function (array $changes, string $field, string $message) {
    expect($this->template->validate(importRoomRow($changes), $this->context)->errors)->toBe([$field => [$message]]);
})->with([
    'tipo desconocido' => [['type' => 'Gimnasio'], 'type', 'El tipo Gimnasio no es válido: usa aula, laboratorio, taller, sala de cómputo o auditorio.'],
    'capacidad cero' => [['capacity' => '0'], 'capacity', 'La capacidad debe ser mayor que cero.'],
    'capacidad con letras' => [['capacity' => 'muchos'], 'capacity', 'El campo capacidad debe ser un número entero.'],
    'departamento inexistente' => [['department' => 'ZZ'], 'department', 'No hay un departamento con la clave ZZ.'],
]);

it('el edificio nuevo avisa y se crea con su clave como nombre, en el campus del lote (S11)', function () {
    $row = importRoomRow(['building' => 'ez', 'code' => 'EZ-1']);

    expect($this->template->validate($row, $this->context)->warnings)
        ->toBe(['building' => ['El edificio EZ no existe en este campus: se crea con su clave como nombre.']]);

    $room = $this->template->apply($row, $this->context);
    $created = Building::where('code', 'EZ')->sole();

    expect($created)->name->toBe('EZ')->campus_id->toBe($this->c2->id)->is_active->toBeTrue()
        ->and($room->fresh())->building_id->toBe($created->id)->campus_id->toBe($this->c2->id);
});

it('un edificio de otro campus con la misma clave no cuenta: se crea el del campus del lote', function () {
    Building::factory()->for($this->c1)->create(['code' => 'EQ']);

    $room = $this->template->apply(importRoomRow(['building' => 'EQ', 'code' => 'EQ-1']), $this->context);

    expect(Building::where('code', 'EQ')->count())->toBe(2)
        ->and($room->building->campus_id)->toBe($this->c2->id);
});

it('un edificio inactivo avisa y uno borrado es error, porque importar no restaura', function () {
    Building::factory()->for($this->c2)->inactive()->create(['code' => 'EI']);
    Building::factory()->for($this->c2)->create(['code' => 'EB'])->delete();

    expect($this->template->validate(importRoomRow(['building' => 'EI']), $this->context)->warnings)
        ->toBe(['building' => ['El edificio EI está inactivo.']])
        ->and($this->template->validate(importRoomRow(['building' => 'EB']), $this->context)->errors)
        ->toBe(['building' => ['Hay un edificio borrado con la clave EB; una importación no lo restaura.']]);
});

it('un departamento inactivo solo avisa', function () {
    Department::factory()->inactive()->create(['code' => 'XI']);

    $issues = $this->template->validate(importRoomRow(['department' => 'XI']), $this->context);

    expect($issues->errors)->toBe([])->and($issues->warnings)->toBe(['department' => ['El departamento XI está inactivo.']]);
});

it('da de alta el salón con su tipo, capacidad y dueño, y aplicar dos veces no cambia nada', function () {
    $room = $this->template->apply(importRoomRow(), $this->context);

    expect($room->fresh())
        ->code->toBe('EA-101')->name->toBe('Aula 101')->type->toBe(RoomType::Aula)->capacity->toBe(40)
        ->department_id->toBe($this->owner->id)->building_id->toBe($this->building->id)
        ->campus_id->toBe($this->c2->id)->is_active->toBeTrue()
        ->and($room->wasRecentlyCreated)->toBeTrue();

    $again = $this->template->apply(importRoomRow(), $this->context);

    expect($again->wasRecentlyCreated)->toBeFalse()->and($again->wasChanged())->toBeFalse()
        ->and(Room::count())->toBe(1)->and(Building::count())->toBe(1);
});

it('actualiza por clave en el campus del lote y no pisa con vacío el nombre ni el dueño', function () {
    $this->template->apply(importRoomRow(), $this->context);

    $room = $this->template->apply(importRoomRow(['type' => 'Laboratorio', 'capacity' => '25', 'name' => null, 'department' => null]), $this->context);

    expect($room->fresh())->type->toBe(RoomType::Laboratorio)->capacity->toBe(25)->name->toBe('Aula 101')->department_id->toBe($this->owner->id);
});

it('la misma clave en otro campus es otro salón', function () {
    $this->template->apply(importRoomRow(), $this->context);
    $this->template->apply(importRoomRow(), new ImportContext($this->admin, $this->c1));

    expect(Room::pluck('campus_id')->sort()->values()->all())->toBe(collect([$this->c1->id, $this->c2->id])->sort()->values()->all());
});

it('un salón inactivo se actualiza y sigue inactivo, con advertencia; uno borrado es error', function () {
    Room::factory()->for($this->building)->inactive()->create(['code' => 'EA-101', 'capacity' => 10]);
    Room::factory()->for($this->building)->create(['code' => 'EA-102'])->delete();

    $warned = $this->template->validate(importRoomRow(), $this->context);
    $room = $this->template->apply(importRoomRow(), $this->context);

    expect($warned->errors)->toBe([])->and($warned->warnings)->toHaveKey('code')
        ->and($room->fresh())->capacity->toBe(40)->is_active->toBeFalse()
        ->and($this->template->validate(importRoomRow(['code' => 'EA-102']), $this->context)->errors)->toHaveKey('code');
});

it('desactivar faltantes solo toca los salones activos del campus del lote', function () {
    $other = Building::factory()->for($this->c1)->create();
    Room::factory()->for($this->building)->create(['code' => 'EA-101']);
    $missing = Room::factory()->for($this->building)->create(['code' => 'EA-900']);
    Room::factory()->for($this->building)->inactive()->create(['code' => 'EA-901']);
    $foreign = Room::factory()->for($other)->create(['code' => 'EA-900']);

    $batch = validatedRoomsBatch($this->admin, $this->c2, "edificio,clave,nombre,tipo,capacidad,departamento\nEA,EA-101,Aula 101,Aula,40,\n");
    $applied = app(ApplyImportBatch::class)->handle($batch, $this->admin, confirmedMissing: 1);

    expect($applied->status->value)->toBe('applied')
        ->and($missing->fresh()->is_active)->toBeFalse()
        ->and($foreign->fresh()->is_active)->toBeTrue()
        ->and(Room::where('code', 'EA-101')->sole()->is_active)->toBeTrue();
});

it('el CSV de ejemplo valida con 0 errores y avisa de los edificios y el tipo nuevos', function () {
    $csv = file_get_contents(base_path('tests/Fixtures/imports/rooms.csv'));
    $batch = validatedRoomsBatch($this->admin, $this->c2, $csv);

    $warned = DB::table('import_rows')->where('batch_id', $batch->id)->pluck('warnings')
        ->flatMap(fn (string $w): array => array_keys(json_decode($w, true)))->unique()->all();

    expect($batch->error_rows)->toBe(0)->and($batch->total_rows)->toBe(5)->and($warned)->toBe(['building']);

    app(ApplyImportBatch::class)->handle($batch->fresh(), $this->admin);

    expect(Room::where('campus_id', $this->c2->id)->count())->toBe(5)
        ->and(Room::where('code', 'EA-102')->value('capacity'))->toBe(1200)
        ->and(Room::where('code', 'EL-2')->value('type'))->toBe(RoomType::SalaComputo);
});

it('busca cada edificio y departamento una sola vez por corrida', function () {
    DB::enableQueryLog();

    foreach (['EA-1', 'EA-2', 'EA-3'] as $code) {
        $row = importRoomRow(['code' => $code]);
        $this->template->validate($row, $this->context);
        $this->template->apply($row, $this->context);
    }

    $lookups = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => preg_match('/from "(buildings|departments)"/', $sql) === 1);

    expect($lookups)->toHaveCount(2);
});
