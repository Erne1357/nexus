<?php

use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Rules\Constraints\TeacherOverlap;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ValidationResult;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;
use Tests\Support\Scheduling\States;

/*
| HC-01: un docente no puede tener dos sesiones que se empalmen en el mismo día y periodo, en ningún campus.
*/

/**
 * @return list<Violation>
 */
function teacherOverlapsIn(ScheduleState $state, ValidationScope $scope): array
{
    return [...(new TeacherOverlap)->check($state, $scope)];
}

it('HC-01: dos sesiones del mismo docente que se empalman dan un choque duro en cada grupo, aun en otro campus', function () {
    $state = States::builder()
        ->teacher(States::teacher('T1', 'Ana Pérez'))
        ->subject(States::subject('S-A', 'Cálculo Diferencial'))
        ->subject(States::subject('S-B', 'Química'))
        ->group(States::group('A', teacher: 'T1', code: 'B'))
        ->group(States::group('B', teacher: 'T1', campus: 'C1', code: 'C'))
        ->session('A', 1, '08:00', '10:00')
        ->session('B', 1, '09:00', '11:00')
        ->build();

    $violations = teacherOverlapsIn($state, ValidationScope::groups('A'));

    expect($violations)->toHaveCount(2)
        ->and(array_map(fn (Violation $violation): ?string => $violation->groupId, $violations))->toBe(['A', 'B'])
        ->and($violations[0])
        ->rule->toBe('HC-01')
        ->severity->toBe(ConflictSeverity::Hard)
        ->subjectType->toBe('teacher')
        ->subjectId->toBe('T1')
        ->message->toBe('Ana Pérez ya da Química, grupo C el lunes de 09:00 a 11:00.')
        ->and($violations[0]->related['other_group_id'])->toBe('B');
});

it('HC-01: horas contiguas, otro día, otro docente o un grupo cancelado no se empalman', function () {
    $state = States::builder()
        ->group(States::group('A', teacher: 'T1'))
        ->group(States::group('B', teacher: 'T1'))
        ->group(States::group('C', teacher: 'T1'))
        ->group(States::group('D', teacher: 'T2'))
        ->group(States::group('E', teacher: 'T1', active: false))
        ->session('A', 1, '08:00', '10:00')
        ->session('B', 1, '10:00', '11:00')
        ->session('C', 2, '08:00', '10:00')
        ->session('D', 1, '08:00', '10:00')
        ->session('E', 1, '08:00', '10:00')
        ->build();

    expect(teacherOverlapsIn($state, ValidationScope::load($state, 'C2')))->toBe([]);
});

it('HC-01: solo reporta los choques que tocan el alcance', function () {
    $state = States::builder()
        ->group(States::group('A', teacher: 'T1'))
        ->group(States::group('B', teacher: 'T1'))
        ->group(States::group('C', teacher: 'T2'))
        ->session('A', 3, '07:00', '09:00')
        ->session('B', 3, '08:00', '09:00')
        ->session('C', 3, '07:00', '09:00')
        ->build();

    expect(teacherOverlapsIn($state, ValidationScope::groups('C')))->toBe([])
        ->and(teacherOverlapsIn($state, ValidationScope::teacher('T1')))->toHaveCount(2)
        ->and(teacherOverlapsIn($state, ValidationScope::groups('B')))->toHaveCount(2);
});

it('HC-01: un grupo sin docente no choca con nadie', function () {
    $state = States::builder()
        ->group(States::group('A'))
        ->group(States::group('B', teacher: 'T1'))
        ->session('A', 1, '08:00', '10:00')
        ->session('B', 1, '08:00', '10:00')
        ->build();

    expect(teacherOverlapsIn($state, ValidationScope::groups('A', 'B')))->toBe([]);
});

it('HC-01: dos sesiones contiguas de un grupo que chocan con la misma sesión de otro son dos choques', function () {
    $state = States::builder()
        ->group(States::group('A', teacher: 'T1'))
        ->group(States::group('B', teacher: 'T1'))
        ->session('A', 1, '07:00', '08:00', id: 'a1')
        ->session('A', 1, '08:00', '09:00', id: 'a2')
        ->session('B', 1, '07:00', '09:00', id: 'b1')
        ->build();

    $result = new ValidationResult(teacherOverlapsIn($state, ValidationScope::groups('A')));

    expect($result->forGroup('A'))->toHaveCount(2)
        ->and($result->forGroup('B'))->toHaveCount(2);
});
