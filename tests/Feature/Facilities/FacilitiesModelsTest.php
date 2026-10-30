<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Term;
use App\Modules\Facilities\Enums\RoomDefaultSource;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Models\RoomBlock;
use App\Modules\Facilities\Models\SubjectRoomDefault;

/*
| Modelos y factories de Facilities: relaciones, casts y estados de las factories.
*/

it('un edificio conoce su campus y sus salones', function () {
    $building = Building::factory()->create();
    $room = Room::factory()->for($building)->create();

    expect($building->campus)->not->toBeNull()
        ->and($building->rooms->modelKeys())->toBe([$room->id]);
});

it('el salón de la factory toma el campus de su edificio y sus relaciones responden', function () {
    $department = Department::factory()->create();
    $room = Room::factory()->ownedBy($department)->ofType(RoomType::Laboratorio)->capacity(24)->create()->fresh();

    expect($room)
        ->campus_id->toBe($room->building->campus_id)
        ->type->toBe(RoomType::Laboratorio)
        ->capacity->toBe(24)
        ->and($room->department->is($department))->toBeTrue()
        ->and($room->campus->is($room->building->campus))->toBeTrue();
});

it('las características vuelven como lista', function () {
    $room = Room::factory()->create(['features' => ['proyector', 'aire acondicionado']])->fresh();

    expect($room->features)->toBe(['proyector', 'aire acondicionado'])
        ->and(Room::factory()->create()->fresh()->features)->toBe([]);
});

it('un salón sin dueño tiene departamento nulo y el estado inactive lo desactiva', function () {
    $room = Room::factory()->inactive()->create();

    expect($room->department)->toBeNull()->and($room->is_active)->toBeFalse();
});

it('un bloqueo conoce su salón y su periodo, y las horas vuelven como hora', function () {
    $term = Term::factory()->create();
    $block = RoomBlock::factory()->create(['term_id' => $term->id, 'starts_at' => '07:00', 'ends_at' => '09:30'])->fresh();

    expect($block->room)->toBeInstanceOf(Room::class)
        ->and($block->term->is($term))->toBeTrue()
        ->and(substr($block->starts_at, 0, 5))->toBe('07:00')
        ->and(substr($block->ends_at, 0, 5))->toBe('09:30');
    expect(Room::find($block->room_id)->blocks->modelKeys())->toBe([$block->id]);
});

it('un salón por defecto conoce su materia, su campus y su salón', function () {
    $room = Room::factory()->create();
    $subject = Subject::factory()->create();
    $default = SubjectRoomDefault::factory()->create([
        'subject_id' => $subject->id, 'room_id' => $room->id, 'priority' => 2, 'source' => RoomDefaultSource::Historial,
    ])->fresh();

    expect($default)
        ->priority->toBe(2)
        ->source->toBe(RoomDefaultSource::Historial)
        ->and($default->subject->is($subject))->toBeTrue()
        ->and($default->room->is($room))->toBeTrue()
        ->and($default->campus->is($room->campus))->toBeTrue();
});

it('los modelos llevan borrado lógico', function () {
    $room = Room::factory()->create();
    $room->delete();

    expect(Room::find($room->id))->toBeNull()->and(Room::withTrashed()->find($room->id))->not->toBeNull();
});
