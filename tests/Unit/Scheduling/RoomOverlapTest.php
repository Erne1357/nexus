<?php

use App\Modules\Scheduling\Rules\Constraints\RoomOverlap;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;
use Tests\Support\Scheduling\States;

/*
| HC-02: un salón no puede tener dos sesiones que se empalmen en el mismo día y periodo.
*/

/**
 * @return list<Violation>
 */
function roomOverlapsIn(ScheduleState $state, ValidationScope $scope): array
{
    return [...(new RoomOverlap)->check($state, $scope)];
}

it('HC-02: dos sesiones en el mismo salón que se empalman dan un choque duro en cada grupo', function () {
    $state = States::builder()
        ->room(States::room('LC-1'))
        ->subject(States::subject('S-B', 'Programación'))
        ->group(States::group('A'))
        ->group(States::group('B', code: 'H'))
        ->session('A', 4, '13:00', '15:00', 'LC-1')
        ->session('B', 4, '14:00', '16:00', 'LC-1')
        ->build();

    $violations = roomOverlapsIn($state, ValidationScope::groups('A'));

    expect($violations)->toHaveCount(2)
        ->and($violations[0])
        ->rule->toBe('HC-02')
        ->subjectType->toBe('room')
        ->subjectId->toBe('LC-1')
        ->groupId->toBe('A')
        ->message->toBe('El salón LC-1 ya está ocupado por Programación, grupo H el jueves de 14:00 a 16:00.');
});

it('HC-02: horas contiguas, otro salón, sin salón o un grupo cancelado no ocupan el salón', function () {
    $state = States::builder()
        ->group(States::group('A'))
        ->group(States::group('B'))
        ->group(States::group('C'))
        ->group(States::group('D'))
        ->group(States::group('E', active: false))
        ->session('A', 4, '13:00', '15:00', 'LC-1')
        ->session('B', 4, '15:00', '16:00', 'LC-1')
        ->session('C', 4, '13:00', '15:00', 'LC-2')
        ->session('D', 4, '13:00', '15:00')
        ->session('E', 4, '13:00', '15:00', 'LC-1')
        ->build();

    expect(roomOverlapsIn($state, ValidationScope::load($state, 'C2')))->toBe([]);
});

it('HC-02: validar un salón revisa todas sus sesiones', function () {
    $state = States::builder()
        ->group(States::group('A'))
        ->group(States::group('B'))
        ->session('A', 2, '07:00', '09:00', 'LC-1')
        ->session('B', 2, '08:00', '09:00', 'LC-1')
        ->build();

    expect(roomOverlapsIn($state, ValidationScope::room('LC-1')))->toHaveCount(2)
        ->and(roomOverlapsIn($state, ValidationScope::room('LC-2')))->toBe([]);
});
