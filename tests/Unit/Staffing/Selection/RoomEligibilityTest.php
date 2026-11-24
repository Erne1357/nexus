<?php

use App\Modules\Academic\Enums\RequiredSpace;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Staffing\Rules\RoomEligibility;

/**
 * Los salones de Sistemas: salas 701 (42), 703 (35), 705 (35) y 706 (40); laboratorios 702 (30) y 707 (35).
 *
 * @return list<array{id: string, code: string, type: RoomType, capacity: int, free: bool, blocked: bool}>
 */
function systemsRooms(): array
{
    $room = fn (string $code, RoomType $type, int $capacity): array => ['id' => $code, 'code' => $code, 'type' => $type, 'capacity' => $capacity, 'free' => true, 'blocked' => false];

    return [
        $room('701', RoomType::SalaComputo, 42),
        $room('702', RoomType::Laboratorio, 30),
        $room('703', RoomType::SalaComputo, 35),
        $room('705', RoomType::SalaComputo, 35),
        $room('706', RoomType::SalaComputo, 40),
        $room('707', RoomType::Laboratorio, 35),
    ];
}

it('SEL-03: cupo 28 puede 703 y 705 pero no 701', function () {
    $result = RoomEligibility::evaluate(RequiredSpace::SalaComputo, 28, systemsRooms(), []);

    expect($result['eligible'])->toBe(['703', '705'])
        ->and($result['rejected']['701'])->toBe('Le sobran más de 10 lugares')
        ->and($result['rejected']['706'])->toBe('Le sobran más de 10 lugares');
});

it('SEL-03: cupo 15 en laboratorio, sin ninguno en rango, ofrece solo el 702', function () {
    $result = RoomEligibility::evaluate(RequiredSpace::Laboratorio, 15, systemsRooms(), []);

    expect($result['eligible'])->toBe(['702'])
        ->and($result['rejected']['707'])->toBe('Le sobran más de 10 lugares');
});

it('SEL-03: un salón que no le cabe al grupo se rechaza', function () {
    $result = RoomEligibility::evaluate(RequiredSpace::Laboratorio, 33, systemsRooms(), []);

    expect($result['eligible'])->toBe(['707'])
        ->and($result['rejected']['702'])->toBe('No le cabe el grupo');
});

it('SEL-01: un salón ocupado o bloqueado no se ofrece', function () {
    $rooms = systemsRooms();
    $rooms[2]['free'] = false;
    $rooms[3]['blocked'] = true;

    $result = RoomEligibility::evaluate(RequiredSpace::SalaComputo, 28, $rooms, []);

    expect($result['eligible'])->toBe(['706'])
        ->and($result['rejected']['703'])->toBe('Ocupado a esa hora')
        ->and($result['rejected']['705'])->toBe('Bloqueado');
});

it('SEL-02: una materia básica no ve laboratorios', function () {
    $rooms = [...systemsRooms(), ['id' => 'A1', 'code' => 'A1', 'type' => RoomType::Aula, 'capacity' => 40, 'free' => true, 'blocked' => false]];

    $result = RoomEligibility::evaluate(null, 30, $rooms, ['1|09:00' => 5]);

    expect($result['eligible'])->toContain('A1')
        ->and($result['eligible'])->not->toContain('702')
        ->and($result['eligible'])->not->toContain('707')
        ->and($result['rejected']['702'])->toBe('Solo para materias de laboratorio');
});

it('SEL-02: el aula respeta la capacidad del grupo sin tope', function () {
    $rooms = [
        ['id' => 'A1', 'code' => 'A1', 'type' => RoomType::Aula, 'capacity' => 20, 'free' => true, 'blocked' => false],
        ['id' => 'A2', 'code' => 'A2', 'type' => RoomType::Aula, 'capacity' => 80, 'free' => true, 'blocked' => false],
    ];

    $result = RoomEligibility::evaluate(RequiredSpace::Aula, 30, $rooms, []);

    expect($result['eligible'])->toBe(['A2'])
        ->and($result['rejected']['A1'])->toBe('No le cabe el grupo');
});

it('SEL-02: un taller y un auditorio nunca se ofrecen', function () {
    $rooms = [
        ['id' => 'T1', 'code' => 'T1', 'type' => RoomType::Taller, 'capacity' => 30, 'free' => true, 'blocked' => false],
        ['id' => 'U1', 'code' => 'U1', 'type' => RoomType::Auditorio, 'capacity' => 30, 'free' => true, 'blocked' => false],
    ];

    foreach ([null, RequiredSpace::Aula, RequiredSpace::SalaComputo, RequiredSpace::Laboratorio] as $need) {
        expect(RoomEligibility::evaluate($need, 20, $rooms, [])['eligible'])->toBe([]);
    }
});

it('SEL-04: con 4 salas libres y 4 grupos de sala pendientes a esa hora, la básica ve las salas apartadas', function () {
    // Reserva por (día, hora): salas libres menos grupos de sala pendientes = 4 - 4.
    $result = RoomEligibility::evaluate(null, 28, systemsRooms(), ['1|09:00' => 0]);

    expect($result['eligible'])->toBe([])
        ->and($result['rejected']['703'])->toBe('Apartada para materias de sala');
});

it('SEL-04: con 4 libres y 3 pendientes, la básica sí puede', function () {
    $result = RoomEligibility::evaluate(null, 28, systemsRooms(), ['1|09:00' => 1]);

    expect($result['eligible'])->toBe(['703', '705']);
});

it('SEL-04: basta una hora sin reserva para apartar las salas', function () {
    $result = RoomEligibility::evaluate(null, 28, systemsRooms(), ['1|09:00' => 2, '3|09:00' => 0]);

    expect($result['eligible'])->toBe([]);
});
