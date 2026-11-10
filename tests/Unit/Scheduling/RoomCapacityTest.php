<?php

use App\Modules\Scheduling\Rules\Constraints\RoomCapacity;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;
use Tests\Support\Scheduling\States;

/*
| HC-05: la capacidad del grupo no puede exceder la capacidad del salón de ninguna de sus sesiones.
*/

/**
 * @return list<Violation>
 */
function roomCapacityViolationsIn(ScheduleState $state, ValidationScope $scope): array
{
    return [...(new RoomCapacity)->check($state, $scope)];
}

it('HC-05: un salón más chico que el grupo da un choque duro en el salón', function () {
    $state = States::builder()
        ->room(States::room('A-1', capacity: 30))
        ->subject(States::subject('S-A', 'Cálculo'))
        ->group(States::group('A', capacity: 35))
        ->session('A', 2, '07:00', '09:00', 'A-1')
        ->build();

    $violations = roomCapacityViolationsIn($state, ValidationScope::groups('A'));

    expect($violations)->toHaveCount(1)
        ->and($violations[0])
        ->rule->toBe('HC-05')
        ->subjectType->toBe('room')
        ->subjectId->toBe('A-1')
        ->groupId->toBe('A')
        ->message->toBe('El salón A-1 tiene 30 lugares y Cálculo, grupo A tiene 35 (martes de 7:00 a 9:00).')
        ->and($violations[0]->related)->toMatchArray(['room_capacity' => 30, 'group_capacity' => 35]);
});

it('HC-05: capacidad igual, mayor o una sesión sin salón no violan', function () {
    $state = States::builder()
        ->room(States::room('A-1', capacity: 35))
        ->room(States::room('A-2', capacity: 40))
        ->group(States::group('A', capacity: 35))
        ->group(States::group('B', capacity: 35))
        ->group(States::group('C', capacity: 35))
        ->session('A', 1, '07:00', '08:00', 'A-1')
        ->session('B', 1, '07:00', '08:00', 'A-2')
        ->session('C', 1, '07:00', '08:00')
        ->build();

    expect(roomCapacityViolationsIn($state, ValidationScope::load($state, 'C2')))->toBe([]);
});

it('HC-05: un grupo cancelado o fuera del alcance no se reporta, y validar el salón revisa sus sesiones', function () {
    $state = States::builder()
        ->room(States::room('A-1', capacity: 20))
        ->group(States::group('A'))
        ->group(States::group('B', active: false))
        ->group(States::group('C'))
        ->session('A', 1, '07:00', '08:00', 'A-1')
        ->session('B', 2, '07:00', '08:00', 'A-1')
        ->session('C', 3, '07:00', '08:00', 'A-1')
        ->build();

    expect(roomCapacityViolationsIn($state, ValidationScope::groups('B')))->toBe([])
        ->and(roomCapacityViolationsIn($state, ValidationScope::groups('A')))->toHaveCount(1)
        ->and(roomCapacityViolationsIn($state, ValidationScope::room('A-1')))->toHaveCount(2);
});
