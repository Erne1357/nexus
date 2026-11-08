<?php

use App\Modules\Core\Models\CampusProfile;
use App\Modules\Scheduling\Rules\Constraints\CampusWindow;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;
use Tests\Support\Scheduling\States;

/*
| HC-04: las sesiones deben caer dentro de la ventana del campus y alinearse a `block_minutes`.
*/

/**
 * @return list<Violation>
 */
function campusWindowViolationsIn(ScheduleState $state, ValidationScope $scope): array
{
    return [...(new CampusWindow)->check($state, $scope)];
}

it('HC-04: una sesión en sábado, con un campus de lunes a viernes, es un choque duro', function () {
    $state = States::builder()
        ->subject(States::subject('S-A', 'Materia'))
        ->group(States::group('A'))
        ->session('A', 6, '08:00', '10:00')
        ->build();

    $violations = campusWindowViolationsIn($state, ValidationScope::groups('A'));

    expect($violations)->toHaveCount(1)
        ->and($violations[0])
        ->rule->toBe('HC-04')
        ->subjectType->toBe('group')
        ->subjectId->toBe('A')
        ->groupId->toBe('A')
        ->message->toBe('La sesión del sábado de 08:00 a 10:00 de Materia, grupo A cae fuera de la ventana del campus (lunes a viernes, 7:00 a 21:00).')
        ->and($violations[0]->related)->toMatchArray([
            'day' => 6,
            'starts_at' => '08:00',
            'ends_at' => '10:00',
            'window' => 'lunes a viernes, 7:00 a 21:00',
            'block_minutes' => 60,
        ]);
});

it('HC-04: empezar antes o terminar después de la jornada es un choque', function () {
    $state = States::builder()
        ->group(States::group('A'))
        ->session('A', 1, '06:00', '08:00')
        ->session('A', 2, '20:00', '22:00')
        ->build();

    expect(campusWindowViolationsIn($state, ValidationScope::groups('A')))->toHaveCount(2);
});

it('HC-04: una sesión que no cae en la cuadrícula de bloques es un choque', function () {
    $state = States::builder()
        ->group(States::group('A'))
        ->session('A', 1, '07:30', '09:00')
        ->session('A', 2, '08:00', '09:30')
        ->build();

    expect(campusWindowViolationsIn($state, ValidationScope::groups('A')))->toHaveCount(2);
});

it('HC-04: los bordes de la jornada alineados a bloques no violan', function () {
    $state = States::builder()
        ->group(States::group('A'))
        ->session('A', 1, '07:00', '09:00')
        ->session('A', 5, '19:00', '21:00')
        ->build();

    expect(campusWindowViolationsIn($state, ValidationScope::groups('A')))->toBe([]);
});

it('HC-04: la cuadrícula se cuenta desde el inicio de la jornada del campus', function () {
    $state = States::builder()
        ->profile('C2', CampusProfile::fromArray(['day_start' => '07:30', 'block_minutes' => 90, 'day_end' => '19:30']))
        ->group(States::group('A'))
        ->session('A', 1, '07:30', '10:30')
        ->session('A', 2, '09:00', '10:30')
        ->session('A', 3, '08:00', '09:30')
        ->build();

    $violations = campusWindowViolationsIn($state, ValidationScope::groups('A'));

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->related['day'])->toBe(3);
});

it('HC-04: un campus sin bloques de tiempo, un grupo cancelado o fuera del alcance no se revisan', function () {
    $state = States::builder()
        ->profile('C1', CampusProfile::fromArray(['uses_time_blocks' => false]))
        ->group(States::group('A', campus: 'C1'))
        ->group(States::group('B', active: false))
        ->group(States::group('C'))
        ->session('A', 6, '05:00', '06:30')
        ->session('B', 6, '05:00', '06:30')
        ->session('C', 6, '05:00', '06:30')
        ->build();

    expect(campusWindowViolationsIn($state, ValidationScope::groups('A', 'B')))->toBe([])
        ->and(campusWindowViolationsIn($state, ValidationScope::groups('C')))->toHaveCount(1);
});
