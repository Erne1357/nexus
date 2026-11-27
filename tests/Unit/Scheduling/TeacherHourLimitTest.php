<?php

use App\Modules\Scheduling\Rules\TeacherHourLimit;
use Tests\Support\Scheduling\States;

/*
| La cuenta de horas de HC-08 que comparten la regla, el ranking y la carga por docente de Staffing.
*/

it('HC-08: suma teoría más práctica de cada grupo activo del docente en todos los campus contra frente a grupo + 2 más la tolerancia', function () {
    $state = States::builder()
        ->setting('staffing.extra_hours_tolerance', 2)
        ->teacher(States::teacher('T1', base: 30, external: 4, teaching: 18))
        ->subject(States::subject('S-A', theory: 3, practice: 2))
        ->subject(States::subject('S-B', theory: 4, practice: 0))
        ->group(States::group('A', teacher: 'T1', subject: 'S-A'))
        ->group(States::group('B', teacher: 'T1', subject: 'S-B', campus: 'C1'))
        ->group(States::group('C', teacher: 'T1', subject: 'S-A', active: false))
        ->build();

    expect(TeacherHourLimit::of($state, 'T1'))->toMatchArray([
        'base_hours' => 30, 'teaching_hours' => 18, 'band_from' => 16, 'band_to' => 20, 'external_hours' => 4, 'assigned_hours' => 9,
        'tolerance' => 2, 'band_limit' => 22, 'limit' => 22, 'remaining_hours' => 9, 'over_band' => false, 'accepted' => false, 'hard' => false,
        'groups' => ['A' => 5, 'B' => 4],
    ])
        ->and(TeacherHourLimit::of($state, 'T1', 'A'))->assigned_hours->toBe(4)->remaining_hours->toBe(14)
        ->and(TeacherHourLimit::of($state, 'T9'))->toBeNull();
});

it('HC-08: a un docente de honorarios lo topa staffing.honorarios_max_hours cuando existe', function () {
    $state = States::builder()
        ->setting('staffing.honorarios_max_hours', 12)
        ->teacher(States::teacher('T1', base: 40, honorarios: true))
        ->build();

    expect(TeacherHourLimit::of($state, 'T1'))->limit->toBe(12)->honorarios_cap->toBe(12);
});

it('SEL-12: las horas autorizadas del periodo suben el límite cuando pasan del tope de la banda', function () {
    $state = States::builder()
        ->setting('staffing.extra_hours_tolerance', 2)
        ->teacher(States::teacher('T1', base: 30, allowance: 26, teaching: 18))
        ->teacher(States::teacher('T2', base: 30, allowance: 10, teaching: 18))
        ->teacher(States::teacher('T3', base: 40, honorarios: true, allowance: 15))
        ->setting('staffing.honorarios_max_hours', 12)
        ->build();

    expect(TeacherHourLimit::of($state, 'T1'))->limit->toBe(26)
        ->and(TeacherHourLimit::of($state, 'T2'))->limit->toBe(22)
        ->and(TeacherHourLimit::of($state, 'T3'))->limit->toBe(15);
});

it('HC-08: la foto del motor trae su propio límite y ahí HC-08 es duro', function () {
    $state = States::builder()
        ->teacher(States::teacher('T1', base: 40, allowance: 30, teaching: 22, engineLimit: 7))
        ->subject(States::subject('S-A', theory: 3, practice: 2))
        ->group(States::group('A', teacher: 'T1', subject: 'S-A'))
        ->build();

    expect(TeacherHourLimit::of($state, 'T1'))->toMatchArray(['band_limit' => 7, 'limit' => 7, 'remaining_hours' => 2, 'hard' => true]);
});

it('HC-08: la banda para mostrar y el aviso sin el nombre, que comparten las pantallas', function () {
    $state = States::builder()
        ->teacher(States::teacher('T1', base: 40, teaching: 2))
        ->teacher(States::teacher('T2', base: 40, teaching: 2, allowance: 5))
        ->teacher(States::teacher('T3', base: 40, teaching: 22))
        ->teacher(States::teacher('T4', base: 40, honorarios: true))
        ->setting('staffing.honorarios_max_hours', 4)
        ->subject(States::subject('S-A', theory: 3, practice: 2))
        ->group(States::group('A', teacher: 'T1', subject: 'S-A'))
        ->group(States::group('B', teacher: 'T2', subject: 'S-A'))
        ->group(States::group('C', teacher: 'T4', subject: 'S-A'))
        ->build();

    expect(TeacherHourLimit::bandLabel(TeacherHourLimit::of($state, 'T3')))->toBe('22 h (20 a 24)')
        ->and(TeacherHourLimit::bandLabel(TeacherHourLimit::of($state, 'T4')))->toBe('4 h (tope de honorarios)')
        ->and(TeacherHourLimit::notice(TeacherHourLimit::of($state, 'T1')))->toBe('Pasa su banda de horas frente a grupo (2 h, de 0 a 4): lleva 5 h. 1 h extra · honorarios por autorizar (jefe de depto).')
        ->and(TeacherHourLimit::notice(TeacherHourLimit::of($state, 'T2')))->toBe('Pasa su banda de horas frente a grupo (2 h, de 0 a 4): lleva 5 h, aceptado por el docente (casilla).')
        ->and(TeacherHourLimit::notice(TeacherHourLimit::of($state, 'T3')))->toBeNull()
        ->and(TeacherHourLimit::notice(TeacherHourLimit::of($state, 'T4')))->toBe('Pasa su tope de honorarios (4 h): lleva 5 h. Sus horas extra quedan como honorarios por autorizar (jefe de depto).');
});

it('PL-02: una materia que no cuenta en la carga del docente le suma 0 h; sus horas de sesión siguen siendo las de la materia', function () {
    $tutoring = States::subject('TUT', theory: 1, practice: 0, countsTeacherHours: false);
    $state = States::builder()
        ->teacher(States::teacher('T1', base: 20, teaching: 12))
        ->subject(States::subject('S-A', theory: 3, practice: 2))
        ->subject($tutoring)
        ->group(States::group('A', teacher: 'T1', subject: 'S-A'))
        ->group(States::group('T', teacher: 'T1', subject: 'TUT'))
        ->build();

    expect(TeacherHourLimit::of($state, 'T1'))->toMatchArray(['assigned_hours' => 5, 'groups' => ['A' => 5, 'T' => 0], 'remaining_hours' => 9])
        ->and($tutoring->weeklyHours())->toBe(1)
        ->and($tutoring->teacherHours())->toBe(0);
});

/*
| PL-03 y HC-22 (confirmado el 27 de noviembre de 2026): quien tiene plaza administrativa no lleva banda de ±2 y no puede pasar
| de 42 h entre clases, externas y administrativas (límite duro).
*/

it('PL-03: plaza administrativa de 36 h con 4 h de clase queda sin banda, sin aviso y en 40 de 42 h', function () {
    $state = States::builder()
        ->teacher(States::teacher('T1', base: 36, administrative: 36))
        ->subject(States::subject('S-A', theory: 2, practice: 2))
        ->group(States::group('A', teacher: 'T1', subject: 'S-A'))
        ->build();
    $hours = TeacherHourLimit::of($state, 'T1');

    expect($hours)->toMatchArray([
        'teaching_hours' => 0, 'band_from' => 0, 'band_to' => 0, 'assigned_hours' => 4, 'administrative_hours' => 36, 'administrative_cap' => 42,
        'administrative_excess' => 0, 'limit' => 6, 'remaining_hours' => 2, 'over_band' => false, 'hard' => false,
    ])
        ->and(TeacherHourLimit::notice($hours))->toBeNull()
        ->and(TeacherHourLimit::administrativeNotice($hours))->toBeNull()
        ->and(TeacherHourLimit::administrativeSummary($hours))->toBe('Plaza administrativa: 36 h · clases 4 h · 40 de 42 h');
});

it('HC-22: 36 + 6 = 42 h cabe y dice que ya no le caben clases; 36 + 8 = 44 h pasa el tope', function () {
    $state = fn (int $theory) => States::builder()
        ->teacher(States::teacher('T1', base: 36, administrative: 36))
        ->subject(States::subject('S-A', theory: $theory, practice: 0))
        ->group(States::group('A', teacher: 'T1', subject: 'S-A'))
        ->build();

    $full = TeacherHourLimit::of($state(6), 'T1');
    $over = TeacherHourLimit::of($state(8), 'T1');

    expect($full)->administrative_excess->toBe(0)->remaining_hours->toBe(0)
        ->and(TeacherHourLimit::administrativeSummary($full))->toBe('Plaza administrativa: 36 h · clases 6 h · 42 de 42 h · ya no le caben clases')
        ->and($over)->administrative_excess->toBe(2)->remaining_hours->toBe(-2)->over_band->toBeFalse()
        ->and(TeacherHourLimit::administrativeNotice($over))->toStartWith('Pasa el tope de su plaza administrativa (42 h)')->toContain('2 h de más');
});

it('PL-03: el tope sale de staffing.administrative_max_hours, por omisión 42, y las horas externas cuentan', function () {
    $state = fn (?int $cap) => tap(States::builder(), fn ($b) => $cap === null ? null : $b->setting('staffing.administrative_max_hours', $cap))
        ->teacher(States::teacher('T1', base: 36, administrative: 30, external: 3))
        ->build();

    expect(TeacherHourLimit::of($state(null), 'T1'))->administrative_cap->toBe(42)->limit->toBe(12)->remaining_hours->toBe(9)
        ->and(TeacherHourLimit::of($state(33), 'T1'))->administrative_cap->toBe(33)->limit->toBe(3)->remaining_hours->toBe(0)->administrative_excess->toBe(0);
});

it('PL-03: sin clases no hay problema, y apoyo más asignatura aplica PL-03 y no la banda (supuesto por confirmar)', function () {
    $state = States::builder()
        ->teacher(States::teacher('T1', base: 36, administrative: 36))
        ->teacher(States::teacher('T2', base: 42, teaching: 6, administrative: 36))
        ->subject(States::subject('S-A', theory: 3, practice: 3))
        ->group(States::group('A', teacher: 'T2', subject: 'S-A'))
        ->build();

    expect(TeacherHourLimit::of($state, 'T1'))->over_band->toBeFalse()->administrative_excess->toBe(0)->remaining_hours->toBe(6)
        ->and(TeacherHourLimit::of($state, 'T2'))->over_band->toBeFalse()->administrative_excess->toBe(0)->remaining_hours->toBe(0)->teaching_hours->toBe(0);
});

/*
| PL-04 y HC-22 (confirmado el 27 de noviembre de 2026): las horas que pasan de frente a grupo + 2 son extra (honorarios);
| plaza + extra no puede pasar de 44 h.
*/

it('PL-04: TC de 40 h con 22 frente a grupo, 24 h sin extra, 26 h con 2 h extra', function () {
    $state = fn (int $theory) => States::builder()
        ->teacher(States::teacher('T1', base: 40, teaching: 22))
        ->subject(States::subject('S-A', theory: $theory, practice: 0))
        ->group(States::group('A', teacher: 'T1', subject: 'S-A'))
        ->build();

    expect(TeacherHourLimit::of($state(24), 'T1'))->extra_hours->toBe(0)->honorarios_excess->toBe(0)->over_band->toBeFalse()
        ->and(TeacherHourLimit::of($state(26), 'T1'))->extra_hours->toBe(2)->plaza_hours->toBe(40)->honorarios_excess->toBe(0)->over_band->toBeTrue();
});

it('HC-22: 40 h de plaza más 5 h extra pasa el tope de 44 h; el tope sale de staffing.max_hours_with_honorarios', function () {
    $state = fn (int $theory, ?int $cap = null) => tap(States::builder(), fn ($b) => $cap === null ? null : $b->setting('staffing.max_hours_with_honorarios', $cap))
        ->teacher(States::teacher('T1', base: 40, teaching: 22))
        ->subject(States::subject('S-A', theory: $theory, practice: 0))
        ->group(States::group('A', teacher: 'T1', subject: 'S-A'))
        ->build();

    $at = TeacherHourLimit::of($state(28), 'T1');
    $over = TeacherHourLimit::of($state(29), 'T1');

    expect($at)->extra_hours->toBe(4)->with_honorarios_max->toBe(44)->honorarios_excess->toBe(0)
        ->and($over)->extra_hours->toBe(5)->honorarios_excess->toBe(1)
        ->and(TeacherHourLimit::honorariosNotice($over))->toStartWith('Pasa el tope con honorarios (44 h)')->toContain('1 h de más')
        ->and(TeacherHourLimit::of($state(28, 42), 'T1'))->honorarios_excess->toBe(2);
});

it('PL-04: sin plaza docente todas sus horas son extra y no hay tope duro; el estado de las horas se escribe como lo pidió el usuario', function () {
    $state = States::builder()
        ->teacher(States::teacher('T1', base: 0, teaching: 0, honorarios: true))
        ->subject(States::subject('S-A', theory: 3, practice: 3))
        ->group(States::group('A', teacher: 'T1', subject: 'S-A'))
        ->build();

    expect(TeacherHourLimit::of($state, 'T1'))->extra_hours->toBe(6)->honorarios_excess->toBe(0)->with_honorarios_max->toBeNull()
        ->and(TeacherHourLimit::extraLabel(2, 'pending'))->toBe('2 h extra · honorarios por autorizar (jefe de depto)')
        ->and(TeacherHourLimit::extraLabel(2, 'authorized'))->toBe('2 h de honorarios autorizadas')
        ->and(TeacherHourLimit::extraLabel(2, 'unauthorized'))->toBe('2 h extra no autorizadas · requiere oficio del docente (pendiente)')
        ->and(TeacherHourLimit::extraLabel(2, 'jefatura'))->toContain('Jefatura: sin honorarios · requiere oficio del docente (pendiente)');
});
