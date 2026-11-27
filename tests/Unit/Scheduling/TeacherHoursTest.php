<?php

use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Rules\Constraints\TeacherHours;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;
use Tests\Support\Scheduling\States;

/*
| HC-08 (tarea 1.50, confirmado el 26 de noviembre de 2026): las horas asignadas a un docente en el periodo (todos los
| campus) más sus horas externas, contra su banda de horas frente a grupo más 2 (art. 147) y la tolerancia configurable;
| honorarios, el tope configurable. Para las personas es un aviso que pide el oficio de conformidad del docente
| (pendiente) o dice que la casilla ya lo aceptó; en la foto del motor sigue siendo choque duro.
*/

/**
 * @return list<Violation>
 */
function teacherHoursIn(ScheduleState $state, ValidationScope $scope): array
{
    return [...(new TeacherHours)->check($state, $scope)];
}

/**
 * Un docente con base 10 h, 8 h frente a grupo (banda de 6 a 10) y dos grupos de 6 h y 5 h (11 h), uno de ellos en otro
 * campus.
 */
function teacherHoursBuilder(int $teaching = 8, int $external = 0, bool $honorarios = false, ?int $allowance = null, ?int $engineLimit = null)
{
    return States::builder()
        ->teacher(States::teacher('T1', 'Ana Pérez', base: 10, external: $external, honorarios: $honorarios, allowance: $allowance, teaching: $teaching, engineLimit: $engineLimit))
        ->subject(States::subject('S-A', theory: 4, practice: 2))
        ->subject(States::subject('S-B', theory: 3, practice: 2))
        ->group(States::group('A', teacher: 'T1'))
        ->group(States::group('B', teacher: 'T1', campus: 'C1'));
}

it('HC-08: pasar la banda de horas frente a grupo da un aviso, no un choque duro, con el desglose exacto', function () {
    $state = teacherHoursBuilder()->build();

    $violations = teacherHoursIn($state, ValidationScope::groups('A'));

    expect($violations)->toHaveCount(1)
        ->and($violations[0])
        ->rule->toBe('HC-08')
        ->severity->toBe(ConflictSeverity::Soft)
        ->subjectType->toBe('teacher')
        ->subjectId->toBe('T1')
        ->groupId->toBe('A')
        ->message->toBe('Ana Pérez pasa su banda de horas frente a grupo (8 h, de 6 a 10): lleva 11 h. Requiere oficio de conformidad del docente (pendiente).')
        ->related->toBe([
            'base_hours' => 10,
            'teaching_hours' => 8,
            'band_from' => 6,
            'band_to' => 10,
            'external_hours' => 0,
            'assigned_hours' => 11,
            'assigned_without_group' => 5,
            'group_hours' => 6,
            'tolerance' => 0,
            'band_limit' => 10,
            'limit' => 10,
            'remaining_hours' => -1,
            'missing_hours' => 1,
            'accepted' => false,
        ]);
});

it('HC-08: las horas externas cuentan contra la banda', function () {
    $violations = teacherHoursIn(teacherHoursBuilder(teaching: 10, external: 2)->build(), ValidationScope::groups('A'));

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->message)->toBe('Ana Pérez pasa su banda de horas frente a grupo (10 h, de 8 a 12): lleva 13 h. Requiere oficio de conformidad del docente (pendiente).')
        ->and($violations[0]->related)->toMatchArray(['external_hours' => 2, 'remaining_hours' => -1, 'missing_hours' => 1]);
});

it('HC-08: justo en frente a grupo + 2 no avisa', function () {
    expect(teacherHoursIn(teacherHoursBuilder(teaching: 9)->build(), ValidationScope::groups('A', 'B')))->toBe([])
        ->and(teacherHoursIn(teacherHoursBuilder(teaching: 11, external: 2)->build(), ValidationScope::groups('A')))->toBe([]);
});

it('HC-08: la tolerancia configurada sube el tope de la banda', function () {
    $within = teacherHoursBuilder()->setting('staffing.extra_hours_tolerance', 1)->build();
    $beyond = teacherHoursBuilder(teaching: 6)->setting('staffing.extra_hours_tolerance', 2)->build();

    $violations = teacherHoursIn($beyond, ValidationScope::groups('A'));

    expect(teacherHoursIn($within, ValidationScope::groups('A')))->toBe([])
        ->and($violations)->toHaveCount(1)
        ->and($violations[0]->message)->toBe('Ana Pérez pasa su banda de horas frente a grupo (6 h, de 4 a 8): lleva 11 h. Requiere oficio de conformidad del docente (pendiente).')
        ->and($violations[0]->related)->toMatchArray(['teaching_hours' => 6, 'tolerance' => 2, 'band_limit' => 10, 'limit' => 10]);
});

it('HC-08: un docente de honorarios usa el tope configurado en lugar de la banda', function () {
    $capped = teacherHoursBuilder(teaching: 40, honorarios: true)->setting('staffing.honorarios_max_hours', 10)->build();
    $roomy = teacherHoursBuilder(teaching: 1, honorarios: true)->setting('staffing.honorarios_max_hours', 20)->build();
    $notHonorarios = teacherHoursBuilder(teaching: 40)->setting('staffing.honorarios_max_hours', 5)->build();
    $unset = teacherHoursBuilder(honorarios: true)->build();

    expect(teacherHoursIn($capped, ValidationScope::groups('A')))->toHaveCount(1)
        ->and(teacherHoursIn($capped, ValidationScope::groups('A'))[0])
        ->severity->toBe(ConflictSeverity::Soft)
        ->message->toBe('Ana Pérez pasa su tope de honorarios (10 h): lleva 11 h. Requiere oficio de conformidad del docente (pendiente).')
        ->related->toMatchArray(['limit' => 10, 'missing_hours' => 1])
        ->and(teacherHoursIn($roomy, ValidationScope::groups('A')))->toBe([])
        ->and(teacherHoursIn($notHonorarios, ValidationScope::groups('A')))->toBe([])
        ->and(teacherHoursIn($unset, ValidationScope::groups('A')))->toHaveCount(1);
});

it('HC-08: con la casilla vigente que cubre sus horas (SEL-12) el aviso dice que el docente lo aceptó', function () {
    $violations = teacherHoursIn(teacherHoursBuilder(allowance: 11)->build(), ValidationScope::groups('A'));

    expect($violations)->toHaveCount(1)
        ->and($violations[0])
        ->severity->toBe(ConflictSeverity::Soft)
        ->message->toBe('Ana Pérez pasa su banda de horas frente a grupo (8 h, de 6 a 10): lleva 11 h, aceptado por el docente (casilla).')
        ->related->toMatchArray(['band_limit' => 10, 'limit' => 11, 'remaining_hours' => 0, 'missing_hours' => 0, 'accepted' => true]);
});

it('HC-08: si pasa también lo que autorizó la casilla, vuelve a pedir el oficio', function () {
    $violations = teacherHoursIn(teacherHoursBuilder(allowance: 10)->build(), ValidationScope::groups('A'));

    expect($violations[0]->message)->toEndWith('lleva 11 h. Requiere oficio de conformidad del docente (pendiente).')
        ->and($violations[0]->related)->toMatchArray(['accepted' => false, 'remaining_hours' => -1]);
});

it('HC-08: la banda no baja de cero horas', function () {
    $violations = teacherHoursIn(teacherHoursBuilder(teaching: 1)->build(), ValidationScope::groups('A'));

    expect($violations[0]->message)->toStartWith('Ana Pérez pasa su banda de horas frente a grupo (1 h, de 0 a 3): lleva 11 h.');
});

it('HC-08: en la foto del motor (el límite de la entrada) sigue siendo choque duro', function () {
    $over = teacherHoursIn(teacherHoursBuilder(teaching: 40, allowance: 30, engineLimit: 10)->build(), ValidationScope::groups('A'));

    expect($over)->toHaveCount(1)
        ->and($over[0])
        ->severity->toBe(ConflictSeverity::Hard)
        ->message->toBe('Ana Pérez suma 11 h y su límite es 10 h: se pasa por 1 h.')
        ->related->toMatchArray(['limit' => 10, 'missing_hours' => 1, 'accepted' => false])
        ->and(teacherHoursIn(teacherHoursBuilder(teaching: 0, engineLimit: 11)->build(), ValidationScope::groups('A')))->toBe([]);
});

it('HC-08: reporta un aviso por cada grupo activo del docente en el alcance', function () {
    $state = teacherHoursBuilder()->build();

    $byGroup = teacherHoursIn($state, ValidationScope::groups('A', 'B'));
    $byTeacher = teacherHoursIn($state, ValidationScope::teacher('T1'));

    expect(array_map(fn (Violation $violation): ?string => $violation->groupId, $byGroup))->toBe(['A', 'B'])
        ->and($byGroup[1]->related)->toMatchArray(['group_hours' => 5, 'assigned_without_group' => 6])
        ->and(array_map(fn (Violation $violation): ?string => $violation->groupId, $byTeacher))->toBe(['A', 'B']);
});

it('HC-08: no cuenta grupos cancelados ni otros docentes, y salta al docente que la foto no trae', function () {
    $state = States::builder()
        ->teacher(States::teacher('T1', 'Ana Pérez', base: 6, teaching: 4))
        ->subject(States::subject('S-A', theory: 4, practice: 2))
        ->subject(States::subject('S-B', theory: 3, practice: 2))
        ->subject(States::subject('S-C', theory: 3, practice: 2))
        ->group(States::group('A', teacher: 'T1'))
        ->group(States::group('B', teacher: 'T1', active: false))
        ->group(States::group('C', teacher: 'T2'))
        ->build();

    expect(teacherHoursIn($state, ValidationScope::groups('A', 'B', 'C')))->toBe([]);
});
