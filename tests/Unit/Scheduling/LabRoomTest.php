<?php

use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Rules\Constraints\LabRoom;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\State\StateSession;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;
use Tests\Support\Scheduling\States;

/*
| HC-06: una materia con `needs_lab` solo puede tener sesiones prácticas en salones de tipo laboratorio, taller o
| sala de cómputo.
*/

/**
 * @return list<Violation>
 */
function labRoomViolationsIn(ScheduleState $state, ValidationScope $scope): array
{
    return [...(new LabRoom)->check($state, $scope)];
}

function labSession(string $group, ?string $room, SessionKind $kind): StateSession
{
    return new StateSession("{$group}-s", $group, 3, 540, 660, $room, $kind);
}

it('HC-06: una práctica de materia con laboratorio en un aula es un choque duro', function () {
    $state = States::builder()
        ->room(States::room('A-1', type: RoomType::Aula))
        ->subject(States::subject('S-A', 'Química', lab: true))
        ->group(States::group('A'))
        ->session(labSession('A', 'A-1', SessionKind::Practica))
        ->build();

    $violations = labRoomViolationsIn($state, ValidationScope::groups('A'));

    expect($violations)->toHaveCount(1)
        ->and($violations[0])
        ->rule->toBe('HC-06')
        ->subjectType->toBe('room')
        ->subjectId->toBe('A-1')
        ->groupId->toBe('A')
        ->message->toBe('Química, grupo A necesita laboratorio, taller o sala de cómputo para su práctica del miércoles de 09:00 a 11:00, y el salón A-1 es de tipo aula.')
        ->and($violations[0]->related)->toMatchArray(['room_type' => RoomType::Aula->value]);
});

it('HC-06: teoría en aula, práctica en espacio de práctica, sin salón o sin laboratorio no violan', function () {
    $state = States::builder()
        ->room(States::room('A-1', type: RoomType::Aula))
        ->room(States::room('L-1', type: RoomType::Laboratorio))
        ->subject(States::subject('S-A', lab: true))
        ->group(States::group('A'))
        ->group(States::group('B', subject: 'S-A'))
        ->group(States::group('C'))
        ->subject(States::subject('S-C', lab: false))
        ->session(labSession('A', 'A-1', SessionKind::Teoria))
        ->session(labSession('B', 'L-1', SessionKind::Practica))
        ->session(labSession('B', null, SessionKind::Practica))
        ->session(labSession('C', 'A-1', SessionKind::Practica))
        ->build();

    expect(labRoomViolationsIn($state, ValidationScope::groups('A', 'B')))->toBe([]);
});

it('HC-06: un grupo cancelado o fuera del alcance no se reporta', function () {
    $state = States::builder()
        ->room(States::room('A-1', type: RoomType::Aula))
        ->subject(States::subject('S-A', lab: true))
        ->subject(States::subject('S-B', lab: true))
        ->group(States::group('A'))
        ->group(States::group('B', active: false))
        ->session(labSession('A', 'A-1', SessionKind::Practica))
        ->session(labSession('B', 'A-1', SessionKind::Practica))
        ->build();

    expect(labRoomViolationsIn($state, ValidationScope::groups('B')))->toBe([])
        ->and(labRoomViolationsIn($state, ValidationScope::room('A-1')))->toHaveCount(1);
});
