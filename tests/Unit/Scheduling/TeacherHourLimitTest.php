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
        ->and(TeacherHourLimit::notice(TeacherHourLimit::of($state, 'T1')))->toBe('Pasa su banda de horas frente a grupo (2 h, de 0 a 4): lleva 5 h. Requiere oficio de conformidad del docente (pendiente).')
        ->and(TeacherHourLimit::notice(TeacherHourLimit::of($state, 'T2')))->toBe('Pasa su banda de horas frente a grupo (2 h, de 0 a 4): lleva 5 h, aceptado por el docente (casilla).')
        ->and(TeacherHourLimit::notice(TeacherHourLimit::of($state, 'T3')))->toBeNull()
        ->and(TeacherHourLimit::notice(TeacherHourLimit::of($state, 'T4')))->toBe('Pasa su tope de honorarios (4 h): lleva 5 h. Requiere oficio de conformidad del docente (pendiente).');
});
