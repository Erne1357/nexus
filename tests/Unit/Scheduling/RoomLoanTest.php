<?php

use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Rules\Constraints\RoomLoan;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\State\StateRoomLoan;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;
use Tests\Support\Scheduling\States;

/*
| HC-10: un salón con `department_id` dueño solo puede usarse por otro departamento si existe un acuerdo de préstamo
| `accepted` que cubra el día y la hora.
*/

/**
 * @return list<Violation>
 */
function roomLoanIn(ScheduleState $state, ValidationScope $scope): array
{
    return [...(new RoomLoan)->check($state, $scope)];
}

function roomLoanBuilder(?string $roomDepartment = 'D2')
{
    return States::builder()
        ->room(States::room('R1', department: $roomDepartment))
        ->group(States::group('A', department: 'D1'));
}

it('HC-10: usar el salón de otro departamento sin préstamo da un choque duro', function () {
    $state = roomLoanBuilder()->session('A', 1, '09:00', '11:00', 'R1')->build();

    $violations = roomLoanIn($state, ValidationScope::groups('A'));

    expect($violations)->toHaveCount(1)
        ->and($violations[0])
        ->rule->toBe('HC-10')
        ->severity->toBe(ConflictSeverity::Hard)
        ->subjectType->toBe('room')
        ->subjectId->toBe('R1')
        ->groupId->toBe('A')
        ->message->toBe('El salón R1 es de otro departamento y no hay préstamo para el lunes de 9:00 a 11:00 (sin préstamo).')
        ->related->toBe(['session_id' => 'A-0', 'room_id' => 'R1', 'owner_department_id' => 'D2', 'loan' => 'none']);
});

it('HC-10: un préstamo propuesto que toca la sesión se reporta como pendiente', function () {
    $state = roomLoanBuilder()
        ->session('A', 1, '09:00', '11:00', 'R1')
        ->loan(new StateRoomLoan('A', 'R1', 1, 9 * 60, 11 * 60, false))
        ->build();

    $violations = roomLoanIn($state, ValidationScope::groups('A'));

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->message)->toBe('El salón R1 es de otro departamento y su préstamo para el lunes de 9:00 a 11:00 sigue pendiente (préstamo pendiente).')
        ->and($violations[0]->related['loan'])->toBe('pending');
});

it('HC-10: un préstamo aceptado cubre la sesión, también en dos piezas contiguas', function () {
    $whole = roomLoanBuilder()
        ->session('A', 1, '09:00', '11:00', 'R1')
        ->loan(new StateRoomLoan('A', 'R1', 1, 8 * 60, 12 * 60, true))
        ->build();
    $pieces = roomLoanBuilder()
        ->session('A', 1, '09:00', '11:00', 'R1')
        ->loan(new StateRoomLoan('A', 'R1', 1, 9 * 60, 10 * 60, true))
        ->loan(new StateRoomLoan('A', 'R1', 1, 10 * 60, 11 * 60, true))
        ->build();

    expect(roomLoanIn($whole, ValidationScope::groups('A')))->toBe([])
        ->and(roomLoanIn($pieces, ValidationScope::groups('A')))->toBe([]);
});

it('HC-10: un préstamo que cubre solo una parte, de otro día o de otro salón no alcanza', function () {
    $partial = roomLoanBuilder()
        ->session('A', 1, '09:00', '11:00', 'R1')
        ->loan(new StateRoomLoan('A', 'R1', 1, 9 * 60, 10 * 60, true))
        ->build();
    $otherDay = roomLoanBuilder()
        ->session('A', 1, '09:00', '11:00', 'R1')
        ->loan(new StateRoomLoan('A', 'R1', 2, 9 * 60, 11 * 60, true))
        ->loan(new StateRoomLoan('A', 'R2', 1, 9 * 60, 11 * 60, true))
        ->build();
    $gap = roomLoanBuilder()
        ->session('A', 1, '09:00', '12:00', 'R1')
        ->loan(new StateRoomLoan('A', 'R1', 1, 9 * 60, 10 * 60, true))
        ->loan(new StateRoomLoan('A', 'R1', 1, 11 * 60, 12 * 60, true))
        ->build();

    expect(roomLoanIn($partial, ValidationScope::groups('A')))->toHaveCount(1)
        ->and(roomLoanIn($otherDay, ValidationScope::groups('A')))->toHaveCount(1)
        ->and(roomLoanIn($otherDay, ValidationScope::groups('A'))[0]->related['loan'])->toBe('none')
        ->and(roomLoanIn($gap, ValidationScope::groups('A')))->toHaveCount(1);
});

it('HC-10: el salón del mismo departamento, sin dueño o sin salón no necesita préstamo', function () {
    $same = roomLoanBuilder('D1')->session('A', 1, '09:00', '11:00', 'R1')->build();
    $noOwner = roomLoanBuilder(null)->session('A', 1, '09:00', '11:00', 'R1')->build();
    $noRoom = roomLoanBuilder()->session('A', 1, '09:00', '11:00')->build();

    expect(roomLoanIn($same, ValidationScope::groups('A')))->toBe([])
        ->and(roomLoanIn($noOwner, ValidationScope::groups('A')))->toBe([])
        ->and(roomLoanIn($noRoom, ValidationScope::groups('A')))->toBe([]);
});

it('HC-10: ignora grupos cancelados y grupos fuera del alcance', function () {
    $state = States::builder()
        ->room(States::room('R1', department: 'D2'))
        ->group(States::group('A', department: 'D1', active: false))
        ->group(States::group('B', department: 'D1'))
        ->session('A', 1, '09:00', '11:00', 'R1')
        ->session('B', 1, '09:00', '11:00', 'R1')
        ->build();

    expect(roomLoanIn($state, ValidationScope::groups('A')))->toBe([])
        ->and(roomLoanIn($state, ValidationScope::groups('A', 'B')))->toHaveCount(1);
});
