<?php

use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Rules\Constraints\TeacherAvailability;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;
use Tests\Support\Scheduling\States;

/*
| HC-09: un docente no puede ser asignado en bloques marcados `unavailable` en su disponibilidad del periodo.
*/

/**
 * @return list<Violation>
 */
function teacherAvailabilityIn(ScheduleState $state, ValidationScope $scope): array
{
    return [...(new TeacherAvailability)->check($state, $scope)];
}

it('HC-09: una sesión que cae en un bloque no disponible da un choque duro', function () {
    $state = States::builder()
        ->teacher(States::teacher('T1', 'Ana Pérez', unavailable: [[1, 9 * 60, 11 * 60]]))
        ->group(States::group('A', teacher: 'T1'))
        ->session('A', 1, '10:00', '12:00')
        ->build();

    $violations = teacherAvailabilityIn($state, ValidationScope::groups('A'));

    expect($violations)->toHaveCount(1)
        ->and($violations[0])
        ->rule->toBe('HC-09')
        ->severity->toBe(ConflictSeverity::Hard)
        ->subjectType->toBe('teacher')
        ->subjectId->toBe('T1')
        ->groupId->toBe('A')
        ->message->toBe('Ana Pérez no está disponible el lunes de 10:00 a 12:00.')
        ->related->toBe(['day' => 1, 'starts_at' => '10:00', 'ends_at' => '12:00']);
});

it('HC-09: bloque contiguo, otro día o sin docente no violan', function () {
    $state = States::builder()
        ->teacher(States::teacher('T1', unavailable: [[1, 9 * 60, 10 * 60]]))
        ->group(States::group('A', teacher: 'T1'))
        ->group(States::group('B'))
        ->group(States::group('C', teacher: 'T9'))
        ->session('A', 1, '10:00', '11:00')
        ->session('A', 1, '08:00', '09:00')
        ->session('A', 2, '09:00', '10:00')
        ->session('B', 1, '09:00', '10:00')
        ->session('C', 1, '09:00', '10:00')
        ->build();

    expect(teacherAvailabilityIn($state, ValidationScope::groups('A', 'B', 'C')))->toBe([]);
});

it('HC-09: ignora grupos cancelados y grupos fuera del alcance', function () {
    $state = States::builder()
        ->teacher(States::teacher('T1', unavailable: [[1, 9 * 60, 10 * 60]]))
        ->group(States::group('A', teacher: 'T1', active: false))
        ->group(States::group('B', teacher: 'T1'))
        ->session('A', 1, '09:00', '10:00')
        ->session('B', 1, '09:00', '10:00')
        ->build();

    expect(teacherAvailabilityIn($state, ValidationScope::groups('A')))->toBe([])
        ->and(teacherAvailabilityIn($state, ValidationScope::groups('A', 'B')))->toHaveCount(1);
});
