<?php

use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Rules\Constraints\CampusTravel;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;
use Tests\Support\Scheduling\States;

/*
| SC-09: si un docente tiene dos sesiones seguidas el mismo día en campus distintos con menos del mínimo de traslado
| (`staffing.min_campus_travel_minutes`, 60 por omisión) entre el fin de una y el inicio de la otra, es un aviso: no
| bloquea.
*/

/**
 * @return list<Violation>
 */
function campusTravelIn(ScheduleState $state, ValidationScope $scope): array
{
    return [...(new CampusTravel)->check($state, $scope)];
}

function campusTravelBuilder()
{
    return States::builder()
        ->campusCode('C1', 'C1')
        ->campusCode('C2', 'C2')
        ->teacher(States::teacher('T1', 'Ana Pérez'))
        ->subject(States::subject('S-A', 'Cálculo Diferencial'))
        ->subject(States::subject('S-B', 'Química'))
        ->group(States::group('A', campus: 'C1', teacher: 'T1', code: 'A'))
        ->group(States::group('B', campus: 'C2', teacher: 'T1', code: 'H'));
}

it('SC-09: dos sesiones seguidas del docente en campus distintos con menos de 1 h libre dan un aviso en cada grupo', function () {
    $state = campusTravelBuilder()->session('A', 1, '07:00', '09:00')->session('B', 1, '09:30', '11:00')->build();

    $violations = campusTravelIn($state, ValidationScope::groups('A'));

    expect($violations)->toHaveCount(2)
        ->and(array_map(fn (Violation $violation): ?string => $violation->groupId, $violations))->toBe(['A', 'B'])
        ->and($violations[0])
        ->rule->toBe('SC-09')
        ->severity->toBe(ConflictSeverity::Soft)
        ->subjectType->toBe('teacher')
        ->subjectId->toBe('T1')
        ->message->toBe('Ana Pérez tiene 30 min para ir de C1 (09:00) a C2 (09:30) el lunes, entre este grupo y Química, grupo H; el mínimo es 60 min.')
        ->and($violations[0]->related)->toBe([
            'teacher_id' => 'T1', 'session_id' => 'A-0', 'other_session_id' => 'B-0', 'other_group_id' => 'B',
            'campus_id' => 'C1', 'other_campus_id' => 'C2', 'day' => 1, 'free_minutes' => 30, 'min_minutes' => 60,
        ])
        ->and($violations[1]->message)->toBe('Ana Pérez tiene 30 min para ir de C1 (09:00) a C2 (09:30) el lunes, entre Cálculo Diferencial, grupo A, y este grupo; el mínimo es 60 min.');
});

it('SC-09: con 1 h libre o más, en el mismo campus, otro día, empalmadas o con otra sesión de por medio no avisa', function () {
    $hour = campusTravelBuilder()->session('A', 1, '07:00', '09:00')->session('B', 1, '10:00', '11:00')->build();
    $otherDay = campusTravelBuilder()->session('A', 1, '07:00', '09:00')->session('B', 2, '09:00', '11:00')->build();
    $overlap = campusTravelBuilder()->session('A', 1, '07:00', '09:00')->session('B', 1, '08:00', '10:00')->build();
    $sameCampus = States::builder()->teacher(States::teacher('T1'))
        ->group(States::group('A', campus: 'C1', teacher: 'T1'))->group(States::group('B', campus: 'C1', teacher: 'T1'))
        ->session('A', 1, '07:00', '09:00')->session('B', 1, '09:00', '11:00')->build();
    $between = campusTravelBuilder()->group(States::group('M', campus: 'C1', teacher: 'T1'))
        ->session('A', 1, '07:00', '08:00')->session('M', 1, '08:00', '09:00')->session('B', 1, '11:00', '12:00')->build();

    foreach ([$hour, $otherDay, $overlap, $sameCampus, $between] as $state) {
        expect(campusTravelIn($state, ValidationScope::groups('A')))->toBe([]);
    }
});

it('SC-09: el mínimo es la configuración staffing.min_campus_travel_minutes', function () {
    $close = campusTravelBuilder()->setting('staffing.min_campus_travel_minutes', '30')
        ->session('A', 1, '07:00', '09:00')->session('B', 1, '09:20', '11:00')->build();
    $enough = campusTravelBuilder()->setting('staffing.min_campus_travel_minutes', '30')
        ->session('A', 1, '07:00', '09:00')->session('B', 1, '09:40', '11:00')->build();

    expect(campusTravelIn($close, ValidationScope::groups('A')))->toHaveCount(2)
        ->and(campusTravelIn($close, ValidationScope::groups('A'))[0]->related['min_minutes'])->toBe(30)
        ->and(campusTravelIn($enough, ValidationScope::groups('A')))->toBe([]);
});

it('SC-09: solo revisa al docente de lo que se valida e ignora grupos cancelados', function () {
    $state = campusTravelBuilder()->group(States::group('Z', campus: 'C1'))
        ->session('A', 1, '07:00', '09:00')->session('B', 1, '09:30', '11:00')->build();
    $cancelled = campusTravelBuilder()->group(States::group('B', campus: 'C2', teacher: 'T1', active: false))
        ->session('A', 1, '07:00', '09:00')->session('B', 1, '09:30', '11:00')->build();

    expect(campusTravelIn($state, ValidationScope::groups('Z')))->toBe([])
        ->and(campusTravelIn($state, new ValidationScope([], ['T1'])))->toHaveCount(2)
        ->and(campusTravelIn($cancelled, ValidationScope::groups('A')))->toBe([]);
});
