<?php

use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Rules\Constraints\TeacherHours;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;
use Tests\Support\Scheduling\States;

/*
| HC-08: las horas asignadas a un docente en el periodo (todos los campus) más sus horas externas no pueden exceder
| sus horas base más la tolerancia configurable. Honorarios: tope configurable.
*/

/**
 * @return list<Violation>
 */
function teacherHoursIn(ScheduleState $state, ValidationScope $scope): array
{
    return [...(new TeacherHours)->check($state, $scope)];
}

/**
 * Un docente con base 10 h y dos grupos de 6 h y 5 h (11 h), uno de ellos en otro campus.
 */
function teacherHoursBuilder(int $base = 10, int $external = 0, bool $honorarios = false)
{
    return States::builder()
        ->teacher(States::teacher('T1', 'Ana Pérez', base: $base, external: $external, honorarios: $honorarios))
        ->subject(States::subject('S-A', theory: 4, practice: 2))
        ->subject(States::subject('S-B', theory: 3, practice: 2))
        ->group(States::group('A', teacher: 'T1'))
        ->group(States::group('B', teacher: 'T1', campus: 'C1'));
}

it('HC-08: pasarse de las horas base da un choque duro con el desglose exacto', function () {
    $state = teacherHoursBuilder()->build();

    $violations = teacherHoursIn($state, ValidationScope::groups('A'));

    expect($violations)->toHaveCount(1)
        ->and($violations[0])
        ->rule->toBe('HC-08')
        ->severity->toBe(ConflictSeverity::Hard)
        ->subjectType->toBe('teacher')
        ->subjectId->toBe('T1')
        ->groupId->toBe('A')
        ->message->toBe('Ana Pérez suma 11 h y su límite es 10 h: se pasa por 1 h.')
        ->related->toBe([
            'base_hours' => 10,
            'external_hours' => 0,
            'assigned_hours' => 11,
            'assigned_without_group' => 5,
            'group_hours' => 6,
            'tolerance' => 0,
            'limit' => 10,
            'remaining_hours' => -1,
            'missing_hours' => 1,
        ]);
});

it('HC-08: las horas externas cuentan contra el límite', function () {
    $violations = teacherHoursIn(teacherHoursBuilder(base: 12, external: 2)->build(), ValidationScope::groups('A'));

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->message)->toBe('Ana Pérez suma 13 h y su límite es 12 h: se pasa por 1 h.')
        ->and($violations[0]->related)->toMatchArray(['external_hours' => 2, 'remaining_hours' => -1, 'missing_hours' => 1]);
});

it('HC-08: justo en el límite no viola', function () {
    expect(teacherHoursIn(teacherHoursBuilder(base: 11)->build(), ValidationScope::groups('A', 'B')))->toBe([])
        ->and(teacherHoursIn(teacherHoursBuilder(base: 13, external: 2)->build(), ValidationScope::groups('A')))->toBe([]);
});

it('HC-08: la tolerancia configurada sube el límite', function () {
    $within = teacherHoursBuilder()->setting('staffing.extra_hours_tolerance', 1)->build();
    $beyond = States::builder()
        ->teacher(States::teacher('T1', 'Ana Pérez', base: 8))
        ->subject(States::subject('S-A', theory: 4, practice: 2))
        ->subject(States::subject('S-B', theory: 3, practice: 2))
        ->group(States::group('A', teacher: 'T1'))
        ->group(States::group('B', teacher: 'T1'))
        ->setting('staffing.extra_hours_tolerance', 2)
        ->build();

    $violations = teacherHoursIn($beyond, ValidationScope::groups('A'));

    expect(teacherHoursIn($within, ValidationScope::groups('A')))->toBe([])
        ->and($violations)->toHaveCount(1)
        ->and($violations[0]->message)->toBe('Ana Pérez suma 11 h y su límite es 10 h: se pasa por 1 h.')
        ->and($violations[0]->related)->toMatchArray(['base_hours' => 8, 'tolerance' => 2, 'limit' => 10]);
});

it('HC-08: un docente de honorarios usa el tope configurado en lugar de base más tolerancia', function () {
    $capped = teacherHoursBuilder(base: 40, honorarios: true)->setting('staffing.honorarios_max_hours', 10)->build();
    $roomy = teacherHoursBuilder(base: 1, honorarios: true)->setting('staffing.honorarios_max_hours', 20)->build();
    $notHonorarios = teacherHoursBuilder(base: 40)->setting('staffing.honorarios_max_hours', 5)->build();
    $unset = teacherHoursBuilder(base: 10, honorarios: true)->build();

    expect(teacherHoursIn($capped, ValidationScope::groups('A')))->toHaveCount(1)
        ->and(teacherHoursIn($capped, ValidationScope::groups('A'))[0]->related)->toMatchArray(['limit' => 10, 'missing_hours' => 1])
        ->and(teacherHoursIn($roomy, ValidationScope::groups('A')))->toBe([])
        ->and(teacherHoursIn($notHonorarios, ValidationScope::groups('A')))->toBe([])
        ->and(teacherHoursIn($unset, ValidationScope::groups('A')))->toHaveCount(1);
});

it('HC-08: reporta un choque por cada grupo activo del docente en el alcance', function () {
    $state = teacherHoursBuilder()->build();

    $byGroup = teacherHoursIn($state, ValidationScope::groups('A', 'B'));
    $byTeacher = teacherHoursIn($state, ValidationScope::teacher('T1'));

    expect(array_map(fn (Violation $violation): ?string => $violation->groupId, $byGroup))->toBe(['A', 'B'])
        ->and($byGroup[1]->related)->toMatchArray(['group_hours' => 5, 'assigned_without_group' => 6])
        ->and(array_map(fn (Violation $violation): ?string => $violation->groupId, $byTeacher))->toBe(['A', 'B']);
});

it('HC-08: no cuenta grupos cancelados ni otros docentes, y salta al docente que la foto no trae', function () {
    $state = States::builder()
        ->teacher(States::teacher('T1', 'Ana Pérez', base: 6))
        ->subject(States::subject('S-A', theory: 4, practice: 2))
        ->subject(States::subject('S-B', theory: 3, practice: 2))
        ->subject(States::subject('S-C', theory: 3, practice: 2))
        ->group(States::group('A', teacher: 'T1'))
        ->group(States::group('B', teacher: 'T1', active: false))
        ->group(States::group('C', teacher: 'T2'))
        ->build();

    expect(teacherHoursIn($state, ValidationScope::groups('A', 'B', 'C')))->toBe([]);
});
