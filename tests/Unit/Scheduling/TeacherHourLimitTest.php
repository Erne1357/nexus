<?php

use App\Modules\Scheduling\Rules\TeacherHourLimit;
use Tests\Support\Scheduling\States;

/*
| La cuenta de horas de HC-08 que comparten la regla, el ranking y la carga por docente de Staffing.
*/

it('suma teoría más práctica de cada grupo activo del docente en todos los campus contra base más tolerancia', function () {
    $state = States::builder()
        ->setting('staffing.extra_hours_tolerance', 2)
        ->teacher(States::teacher('T1', base: 20, external: 4))
        ->subject(States::subject('S-A', theory: 3, practice: 2))
        ->subject(States::subject('S-B', theory: 4, practice: 0))
        ->group(States::group('A', teacher: 'T1', subject: 'S-A'))
        ->group(States::group('B', teacher: 'T1', subject: 'S-B', campus: 'C1'))
        ->group(States::group('C', teacher: 'T1', subject: 'S-A', active: false))
        ->build();

    expect(TeacherHourLimit::of($state, 'T1'))->toMatchArray([
        'base_hours' => 20, 'external_hours' => 4, 'assigned_hours' => 9, 'tolerance' => 2, 'limit' => 22, 'remaining_hours' => 9,
        'groups' => ['A' => 5, 'B' => 4],
    ])
        ->and(TeacherHourLimit::of($state, 'T1', 'A'))->assigned_hours->toBe(4)->remaining_hours->toBe(14)
        ->and(TeacherHourLimit::of($state, 'T9'))->toBeNull();
});

it('a un docente de honorarios lo topa staffing.honorarios_max_hours cuando existe', function () {
    $state = States::builder()
        ->setting('staffing.honorarios_max_hours', 12)
        ->teacher(States::teacher('T1', base: 40, honorarios: true))
        ->build();

    expect(TeacherHourLimit::of($state, 'T1'))->limit->toBe(12);
});
