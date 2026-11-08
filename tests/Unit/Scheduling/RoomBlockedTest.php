<?php

use App\Modules\Scheduling\Rules\Constraints\RoomBlocked;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\State\StateRoomBlock;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;
use Tests\Support\Scheduling\States;

/*
| HC-11: un salón no puede usarse en bloques con `room_blocks` vigentes.
*/

/**
 * @return list<Violation>
 */
function roomBlockedViolationsIn(ScheduleState $state, ValidationScope $scope): array
{
    return [...(new RoomBlocked)->check($state, $scope)];
}

it('HC-11: una sesión que empalma un bloqueo del salón es un choque duro con el motivo', function () {
    $state = States::builder()
        ->room(States::room('LC-1'))
        ->subject(States::subject('S-A', 'Redes'))
        ->group(States::group('A'))
        ->block(new StateRoomBlock('LC-1', 2, 600, 720, 'Mantenimiento'))
        ->session('A', 2, '09:00', '11:00', 'LC-1')
        ->build();

    $violations = roomBlockedViolationsIn($state, ValidationScope::groups('A'));

    expect($violations)->toHaveCount(1)
        ->and($violations[0])
        ->rule->toBe('HC-11')
        ->subjectType->toBe('room')
        ->subjectId->toBe('LC-1')
        ->groupId->toBe('A')
        ->message->toBe('El salón LC-1 está bloqueado el martes de 10:00 a 12:00 (Mantenimiento) y Redes, grupo A lo usa el martes de 09:00 a 11:00.')
        ->and($violations[0]->related)->toMatchArray(['room_id' => 'LC-1', 'day' => 2, 'starts_at' => '10:00', 'ends_at' => '12:00', 'reason' => 'Mantenimiento']);
});

it('HC-11: sin motivo el mensaje no lo menciona', function () {
    $state = States::builder()
        ->group(States::group('A'))
        ->block(new StateRoomBlock('LC-1', 2, 600, 720))
        ->session('A', 2, '09:00', '11:00', 'LC-1')
        ->build();

    expect(roomBlockedViolationsIn($state, ValidationScope::groups('A'))[0]->message)->not->toContain('(');
});

it('HC-11: horas contiguas, otro día, otro salón o sin salón no violan', function () {
    $state = States::builder()
        ->group(States::group('A'))
        ->group(States::group('B'))
        ->group(States::group('C'))
        ->group(States::group('D'))
        ->block(new StateRoomBlock('LC-1', 2, 600, 720, 'Mantenimiento'))
        ->session('A', 2, '09:00', '10:00', 'LC-1')
        ->session('A', 2, '12:00', '13:00', 'LC-1')
        ->session('B', 3, '10:00', '12:00', 'LC-1')
        ->session('C', 2, '10:00', '12:00', 'LC-2')
        ->session('D', 2, '10:00', '12:00')
        ->build();

    expect(roomBlockedViolationsIn($state, ValidationScope::load($state, 'C2')))->toBe([]);
});

it('HC-11: un grupo cancelado o fuera del alcance no se reporta, y validar el salón revisa sus sesiones', function () {
    $state = States::builder()
        ->group(States::group('A'))
        ->group(States::group('B', active: false))
        ->group(States::group('C'))
        ->block(new StateRoomBlock('LC-1', 2, 600, 720))
        ->session('A', 2, '10:00', '11:00', 'LC-1')
        ->session('B', 2, '10:00', '11:00', 'LC-1')
        ->session('C', 2, '11:00', '12:00', 'LC-1')
        ->build();

    expect(roomBlockedViolationsIn($state, ValidationScope::groups('B')))->toBe([])
        ->and(roomBlockedViolationsIn($state, ValidationScope::groups('A')))->toHaveCount(1)
        ->and(roomBlockedViolationsIn($state, ValidationScope::room('LC-1')))->toHaveCount(2);
});
