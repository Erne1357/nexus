<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Actions\CreateRoom;
use App\Modules\Facilities\Actions\UpdateRoom;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Events\RoomCreated;
use App\Modules\Facilities\Events\RoomUpdated;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Rules\RoomRules;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Actions de Salón. Clave normalizada y única por campus (también contra borrados), capacidad mayor
| que cero, edificio del mismo campus, dueño opcional y existente, características como lista limpia.
*/

beforeEach(function () {
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->edificioA = Building::factory()->for($this->c2)->create(['code' => 'A']);
    $this->edificioC1 = Building::factory()->for($this->c1)->create(['code' => 'A']);
});

function newRoom(Campus $campus, ?Building $building, array $overrides = []): Room
{
    return app(CreateRoom::class)->handle($campus, [
        'building_id' => $building->id,
        'code' => 'A-101',
        'name' => null,
        'type' => 'aula',
        'capacity' => 30,
        ...$overrides,
    ]);
}

it('da de alta un salón activo del campus con la clave en mayúsculas y sin espacios', function () {
    $room = newRoom($this->c2, $this->edificioA, ['code' => ' a - 101 ', 'name' => ' Aula magna ', 'features' => ['  Proyector ', 'proyector', '', 'Aire acondicionado']]);

    expect($room->fresh())->code->toBe('A-101')->name->toBe('Aula magna')->type->toBe(RoomType::Aula)
        ->capacity->toBe(30)->is_active->toBeTrue()->campus_id->toBe($this->c2->id)->department_id->toBeNull()
        ->features->toBe(['Proyector', 'Aire acondicionado']);
});

it('registra en la bitácora el alta y avisa con RoomCreated', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    Event::fake([RoomCreated::class]);

    $room = newRoom($this->c2, $this->edificioA);
    $entry = Activity::sole();

    expect($entry)->event->toBe('created')->description->toBe('Dio de alta el salón A-101')
        ->and($entry->causer->is($admin))->toBeTrue()
        ->and($entry->attribute_changes['attributes'])->toMatchArray(['code' => 'A-101', 'type' => 'aula', 'capacity' => 30, 'building_id' => $this->edificioA->id]);
    Event::assertDispatched(RoomCreated::class, fn (RoomCreated $event): bool => $event->room->is($room));
});

it('rechaza una clave repetida en el campus, también contra un borrado, pero no en otro campus', function (bool $trashed) {
    $existing = newRoom($this->c2, $this->edificioA);
    $trashed && $existing->delete();

    expect(fn () => newRoom($this->c2, $this->edificioA, ['code' => 'a-101']))->toThrow(ValidationException::class, 'Ya existe un salón con la clave A-101');
    expect(newRoom($this->c1, $this->edificioC1, ['code' => 'a-101']))->code->toBe('A-101');
})->with(['activo' => false, 'borrado' => true]);

it('rechaza una capacidad de cero o menos', function (int $capacity) {
    expect(fn () => newRoom($this->c2, $this->edificioA, ['capacity' => $capacity]))
        ->toThrow(ValidationException::class, 'La capacidad debe ser mayor que cero');
})->with([0, -5]);

it('rechaza un edificio de otro campus o inexistente', function () {
    expect(fn () => newRoom($this->c2, $this->edificioC1))->toThrow(ValidationException::class, 'El edificio no es de este campus')
        ->and(fn () => app(CreateRoom::class)->handle($this->c2, ['building_id' => fakeKey(1), 'code' => 'X', 'type' => 'aula', 'capacity' => 10]))
        ->toThrow(ValidationException::class, 'El edificio no es de este campus');
});

it('rechaza un edificio borrado', function () {
    $this->edificioA->delete();

    expect(fn () => newRoom($this->c2, $this->edificioA))->toThrow(ValidationException::class, 'El edificio no es de este campus');
});

it('guarda el dueño y rechaza un departamento inexistente o borrado', function () {
    $department = Department::factory()->create();
    $deleted = Department::factory()->create();
    $deleted->delete();

    expect(newRoom($this->c2, $this->edificioA, ['department_id' => $department->id])->department_id)->toBe($department->id)
        ->and(fn () => newRoom($this->c2, $this->edificioA, ['code' => 'X1', 'department_id' => $deleted->id]))->toThrow(ValidationException::class, 'El departamento dueño no existe')
        ->and(fn () => newRoom($this->c2, $this->edificioA, ['code' => 'X2', 'department_id' => 'no-uuid']))->toThrow(ValidationException::class, 'El departamento dueño no existe');
});

it('rechaza un tipo desconocido', function () {
    expect(fn () => newRoom($this->c2, $this->edificioA, ['type' => 'bodega']))->toThrow(ValidationException::class, 'El tipo de salón no es válido');
});

it('si otra sesión gana la carrera de la clave, responde con el mismo error de validación', function () {
    Room::creating(function (Room $room): void {
        Room::query()->insert(['campus_id' => $room->campus_id, 'building_id' => $room->building_id, 'code' => $room->code, 'type' => 'aula', 'capacity' => 1, 'features' => '[]', 'is_active' => true]);
    });

    expect(fn () => newRoom($this->c2, $this->edificioA))->toThrow(ValidationException::class, 'Ya existe un salón con la clave A-101');
});

it('edita capacidad, tipo, edificio y dueño, con bitácora de lo anterior y de lo nuevo, y avisa', function () {
    $room = newRoom($this->c2, $this->edificioA);
    $other = Building::factory()->for($this->c2)->create(['code' => 'B']);
    $department = Department::factory()->create();
    $logged = Activity::count();
    Event::fake([RoomUpdated::class]);

    app(UpdateRoom::class)->handle($room, ['capacity' => 45, 'type' => 'laboratorio', 'building_id' => $other->id, 'department_id' => $department->id]);

    expect($room->fresh())->capacity->toBe(45)->type->toBe(RoomType::Laboratorio)->building_id->toBe($other->id)->department_id->toBe($department->id)
        ->and(Activity::query()->orderByDesc('id')->firstOrFail()->attribute_changes->toArray())->toMatchArray([
            'attributes' => ['building_id' => $other->id, 'type' => 'laboratorio', 'capacity' => 45, 'department_id' => $department->id],
            'old' => ['building_id' => $this->edificioA->id, 'type' => 'aula', 'capacity' => 30, 'department_id' => null],
        ]);
    Event::assertDispatched(RoomUpdated::class);
});

it('quita al dueño con department_id nulo', function () {
    $department = Department::factory()->create();
    $room = newRoom($this->c2, $this->edificioA, ['department_id' => $department->id]);

    app(UpdateRoom::class)->handle($room, ['department_id' => null]);

    expect($room->fresh()->department_id)->toBeNull();
});

it('activa y desactiva con bitácora', function () {
    $room = newRoom($this->c2, $this->edificioA);
    $logged = Activity::count();

    app(UpdateRoom::class)->handle($room, ['is_active' => false]);

    expect($room->fresh()->is_active)->toBeFalse()
        ->and(Activity::query()->orderByDesc('id')->firstOrFail()->attribute_changes->toArray())->toBe(['attributes' => ['is_active' => false], 'old' => ['is_active' => true]]);
});

it('acepta su propia clave y rechaza la de otro salón, la capacidad inválida y el edificio de otro campus al editar', function () {
    $room = newRoom($this->c2, $this->edificioA);
    newRoom($this->c2, $this->edificioA, ['code' => 'A-102']);

    app(UpdateRoom::class)->handle($room, ['code' => 'a-101', 'name' => 'Aula uno']);
    expect($room->fresh()->name)->toBe('Aula uno');

    expect(fn () => app(UpdateRoom::class)->handle($room, ['code' => 'a-102']))->toThrow(ValidationException::class, 'Ya existe un salón con la clave A-102')
        ->and(fn () => app(UpdateRoom::class)->handle($room, ['capacity' => 0]))->toThrow(ValidationException::class, 'La capacidad debe ser mayor que cero')
        ->and(fn () => app(UpdateRoom::class)->handle($room, ['building_id' => $this->edificioC1->id]))->toThrow(ValidationException::class, 'El edificio no es de este campus');
});

it('sin cambios no escribe, no registra ni avisa', function () {
    $room = newRoom($this->c2, $this->edificioA, ['features' => ['Proyector']]);
    $logged = Activity::count();
    Event::fake([RoomUpdated::class]);
    $updatedAt = $room->fresh()->updated_at;
    $this->travel(1)->minute();

    app(UpdateRoom::class)->handle($room, ['code' => 'a-101', 'capacity' => 30, 'type' => 'aula', 'is_active' => true, 'features' => [' Proyector']]);

    expect($room->fresh()->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and(Activity::count())->toBe($logged);
    Event::assertNotDispatched(RoomUpdated::class);
});

it('no cambia el campus aunque se lo manden', function () {
    $room = newRoom($this->c2, $this->edificioA);

    app(UpdateRoom::class)->handle($room, ['campus_id' => $this->c1->id, 'name' => 'Otro']);

    expect($room->fresh()->campus_id)->toBe($this->c2->id);
});

it('normaliza las características: recorta, quita vacías y repetidas sin distinguir mayúsculas, y conserva el orden', function () {
    expect((new RoomRules)->normalizeFeatures([' Proyector ', '', 'AIRE  acondicionado', 'proyector', "\u{00A0}", 'Pizarrón']))
        ->toBe(['Proyector', 'AIRE acondicionado', 'Pizarrón']);
});

it('rechaza un dueño desactivado al darlo de alta o cambiarlo, pero conserva uno que ya tenía', function () {
    $inactive = Department::factory()->inactive()->create();
    $active = Department::factory()->create();

    expect(fn () => newRoom($this->c2, $this->edificioA, ['code' => 'X1', 'department_id' => $inactive->id]))
        ->toThrow(ValidationException::class, 'El departamento dueño está desactivado');

    $room = newRoom($this->c2, $this->edificioA, ['code' => 'X2', 'department_id' => $active->id]);
    $active->update(['is_active' => false]);

    app(UpdateRoom::class)->handle($room, ['department_id' => $active->id, 'capacity' => 31]);
    expect($room->fresh()->capacity)->toBe(31);

    expect(fn () => app(UpdateRoom::class)->handle($room, ['department_id' => $inactive->id]))
        ->toThrow(ValidationException::class, 'El departamento dueño está desactivado');
});

it('devuelve un salón sin relaciones viejas', function () {
    $room = newRoom($this->c2, $this->edificioA);
    $room->load('building');
    $other = Building::factory()->for($this->c2)->create(['code' => 'B']);

    $updated = app(UpdateRoom::class)->handle($room, ['building_id' => $other->id]);

    expect($updated->relationLoaded('building'))->toBeFalse()->and($updated->building->id)->toBe($other->id);
});
