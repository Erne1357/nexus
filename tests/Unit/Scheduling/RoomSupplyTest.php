<?php

use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Scheduling\Support\RoomSupply;
use App\Modules\Scheduling\Validation\ScheduleStateBuilder;
use App\Modules\Scheduling\Validation\State\StateGroup;
use App\Modules\Scheduling\Validation\State\StateRoom;
use App\Modules\Scheduling\Validation\State\StateRoomBlock;

/*
| RoomSupply: la oferta de salones de un campus en la semana, en memoria. El horario limpio de la demo la usa para no
| colocar una práctica con laboratorio donde ya no queda un salón que le sirva, y el paso de salones para asignar uno
| a cada sesión sin crear HC-02 (ocupado), HC-05 (no cabe), HC-06 (práctica fuera de un espacio de práctica) ni HC-11
| (bloqueado). Las horas van en minutos desde la medianoche.
*/

function supplyRoom(string $code, int $capacity, RoomType $type = RoomType::Aula, ?string $department = null, bool $active = true): StateRoom
{
    return new StateRoom("id-{$code}", 'campus', $code, $capacity, $type, $department, $active);
}

/**
 * @param  list<StateRoom>  $rooms
 * @param  list<StateRoomBlock>  $blocks
 */
function roomSupply(array $rooms, array $blocks = []): RoomSupply
{
    return new RoomSupply($rooms, $blocks);
}

it('aparta el salón más justo donde cabe el grupo (HC-05) y, a igual capacidad, el de clave menor', function () {
    $supply = roomSupply([supplyRoom('A-30', 30), supplyRoom('B-40', 40), supplyRoom('A-40', 40), supplyRoom('C-20', 20)]);

    expect($supply->reserve(1, 420, 480, 35)?->code)->toBe('A-40')
        ->and($supply->reserve(1, 420, 480, 35)?->code)->toBe('B-40')
        ->and($supply->reserve(1, 420, 480, 35))->toBeNull()
        ->and($supply->reserve(1, 420, 480, 25)?->code)->toBe('A-30');
});

it('da a una práctica con laboratorio solo un espacio de práctica (HC-06)', function () {
    $supply = roomSupply([
        supplyRoom('AULA', 40),
        supplyRoom('LAB', 30, RoomType::Laboratorio),
        supplyRoom('TALLER', 30, RoomType::Taller),
        supplyRoom('COMPUTO', 30, RoomType::SalaComputo),
    ]);

    expect($supply->reserve(1, 420, 540, 25, practiceSpace: true)?->code)->toBe('COMPUTO')
        ->and($supply->reserve(1, 420, 540, 25, practiceSpace: true)?->code)->toBe('LAB')
        ->and($supply->reserve(1, 420, 540, 25, practiceSpace: true)?->code)->toBe('TALLER')
        ->and($supply->fits(1, 420, 540, 25, practiceSpace: true))->toBeFalse();
});

it('deja los espacios de práctica para el final cuando la sesión no los pide', function () {
    $supply = roomSupply([supplyRoom('LAB', 30, RoomType::Laboratorio), supplyRoom('AULA', 45), supplyRoom('AUDITORIO', 200, RoomType::Auditorio)]);

    expect($supply->reserve(1, 420, 480, 30)?->code)->toBe('AULA')
        ->and($supply->reserve(1, 420, 480, 30)?->code)->toBe('AUDITORIO')
        ->and($supply->reserve(1, 420, 480, 30)?->code)->toBe('LAB')
        ->and($supply->reserve(1, 420, 480, 30))->toBeNull();
});

it('no da un salón apartado en un tramo que se empalma el mismo día (HC-02), pero sí el contiguo y otro día', function () {
    $supply = roomSupply([supplyRoom('A', 40)]);
    $supply->reserve(2, 480, 600, 30);

    expect($supply->fits(2, 540, 660, 30))->toBeFalse()
        ->and($supply->fits(2, 420, 481, 30))->toBeFalse()
        ->and($supply->fits(2, 600, 660, 30))->toBeTrue()
        ->and($supply->fits(2, 420, 480, 30))->toBeTrue()
        ->and($supply->fits(3, 480, 600, 30))->toBeTrue();
});

it('no da un salón bloqueado en ese tramo (HC-11); un bloqueo contiguo no estorba', function () {
    $supply = roomSupply([supplyRoom('A', 40), supplyRoom('B', 45)], [new StateRoomBlock('id-A', 1, 600, 720, 'Mantenimiento')]);

    expect($supply->find(1, 660, 720, 30)?->code)->toBe('B')
        ->and($supply->find(1, 540, 600, 30)?->code)->toBe('A')
        ->and($supply->find(2, 660, 720, 30)?->code)->toBe('A');
});

it('salta los salones inactivos', function () {
    $supply = roomSupply([supplyRoom('A', 30, active: false), supplyRoom('B', 50)]);

    expect($supply->find(1, 420, 480, 30)?->code)->toBe('B');
});

it('prefiere los salones sin dueño o del departamento del grupo a los que piden préstamo', function () {
    $supply = roomSupply([
        supplyRoom('AJENO', 30, department: 'otro'),
        supplyRoom('PROPIO', 40, department: 'depto'),
        supplyRoom('LIBRE', 45),
    ]);

    expect($supply->reserve(1, 420, 480, 30, departmentId: 'depto')?->code)->toBe('PROPIO')
        ->and($supply->reserve(1, 420, 480, 30, departmentId: 'depto')?->code)->toBe('LIBRE')
        ->and($supply->reserve(1, 420, 480, 30, departmentId: 'depto')?->code)->toBe('AJENO')
        ->and($supply->find(2, 420, 480, 30)?->code)->toBe('AJENO');
});

it('toma primero los salones preferidos que sirven, en su orden', function () {
    $supply = roomSupply([supplyRoom('CHICO', 30), supplyRoom('GRANDE', 60), supplyRoom('MEDIANO', 40), supplyRoom('MINI', 10)]);

    expect($supply->reserve(1, 420, 480, 30, preferred: ['id-MINI', 'id-GRANDE', 'id-MEDIANO'])?->code)->toBe('GRANDE')
        ->and($supply->reserve(1, 420, 480, 30, preferred: ['id-MINI', 'id-GRANDE', 'id-MEDIANO'])?->code)->toBe('MEDIANO')
        ->and($supply->reserve(1, 420, 480, 30, preferred: ['id-GRANDE', 'id-NO-EXISTE'])?->code)->toBe('CHICO');
});

it('cuenta las sesiones que ya tienen salón y libera un tramo apartado', function () {
    $supply = roomSupply([supplyRoom('A', 40)]);
    $supply->occupy('id-A', 1, 420, 540);

    expect($supply->fits(1, 480, 540, 30))->toBeFalse();

    $supply->release('id-A', 1, 420, 540);

    expect($supply->fits(1, 480, 540, 30))->toBeTrue();
});

it('al liberar no quita un bloqueo ni otro tramo del mismo salón', function () {
    $supply = roomSupply([supplyRoom('A', 40)], [new StateRoomBlock('id-A', 1, 420, 480)]);
    $supply->occupy('id-A', 1, 600, 660);
    $supply->occupy('id-A', 1, 720, 780);
    $supply->release('id-A', 1, 420, 480);
    $supply->release('id-A', 1, 600, 660);

    expect($supply->fits(1, 420, 480, 30))->toBeFalse()
        ->and($supply->fits(1, 600, 660, 30))->toBeTrue()
        ->and($supply->fits(1, 720, 780, 30))->toBeFalse();
});

it('se arma desde la foto del validador con los salones del campus, sus bloqueos y las sesiones que ya tienen salón', function () {
    $group = fn (string $id, bool $active = true): StateGroup => new StateGroup($id, 'campus', 'PRES', 'materia', 'depto', 'carrera', 'plan', 1, $id, null, 30, $active);
    $state = (new ScheduleStateBuilder)
        ->room(supplyRoom('A', 40))
        ->room(supplyRoom('B', 40))
        ->room(new StateRoom('id-OTRO', 'otro-campus', 'OTRO', 40))
        ->block(new StateRoomBlock('id-B', 1, 420, 480))
        ->group($group('activo'))
        ->group($group('cancelado', active: false))
        ->session('activo', 1, '07:00', '08:00', 'id-A')
        ->session('cancelado', 1, '08:00', '09:00', 'id-A')
        ->build();

    $supply = RoomSupply::fromState($state, 'campus');

    expect($supply->find(1, 420, 480, 30))->toBeNull()
        ->and($supply->find(1, 480, 540, 30)?->code)->toBe('A')
        ->and($supply->find(2, 420, 480, 30)?->code)->toBe('A');
});

it('buscar no aparta', function () {
    $supply = roomSupply([supplyRoom('A', 40)]);

    expect($supply->find(1, 420, 480, 30)?->code)->toBe('A')
        ->and($supply->find(1, 420, 480, 30)?->code)->toBe('A');
});
