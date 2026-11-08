<?php

use App\Modules\Core\Models\CampusProfile;
use App\Modules\Scheduling\Rules\Constraints\WeeklyHours;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;
use Tests\Support\Scheduling\States;

/*
| HC-12: las horas semanales de sesiones de un grupo deben ser iguales a `theory_hours + practice_hours` de la
| materia (tolerancia configurable por campus para modalidades mixtas).
*/

/**
 * @return list<Violation>
 */
function weeklyHoursViolationsIn(ScheduleState $state, ValidationScope $scope): array
{
    return [...(new WeeklyHours)->check($state, $scope)];
}

it('HC-12: faltar horas da un choque duro que dice cuántas faltan', function () {
    $state = States::builder()
        ->subject(States::subject('S-A', 'Cálculo', theory: 3, practice: 2))
        ->group(States::group('A'))
        ->session('A', 1, '07:00', '08:00')
        ->session('A', 3, '07:00', '09:00')
        ->build();

    $violations = weeklyHoursViolationsIn($state, ValidationScope::groups('A'));

    expect($violations)->toHaveCount(1)
        ->and($violations[0])
        ->rule->toBe('HC-12')
        ->subjectType->toBe('group')
        ->subjectId->toBe('A')
        ->groupId->toBe('A')
        ->message->toBe('Faltan 2 h de las 5 h semanales de Cálculo, grupo A: tiene 3 h.')
        ->and($violations[0]->related)->toMatchArray(['required_minutes' => 300, 'scheduled_minutes' => 180, 'tolerance_minutes' => 0]);
});

it('HC-12: pasarse de horas dice cuántas sobran', function () {
    $state = States::builder()
        ->subject(States::subject('S-A', 'Cálculo', theory: 2, practice: 2))
        ->group(States::group('A'))
        ->session('A', 1, '07:00', '10:00')
        ->session('A', 3, '07:00', '09:00')
        ->build();

    expect(weeklyHoursViolationsIn($state, ValidationScope::groups('A'))[0]->message)
        ->toBe('Sobra 1 h de las 4 h semanales de Cálculo, grupo A: tiene 5 h.');
});

it('HC-12: las horas exactas no violan', function () {
    $state = States::builder()
        ->subject(States::subject('S-A', theory: 3, practice: 2))
        ->group(States::group('A'))
        ->session('A', 1, '07:00', '10:00')
        ->session('A', 3, '07:00', '09:00')
        ->build();

    expect(weeklyHoursViolationsIn($state, ValidationScope::groups('A')))->toBe([]);
});

it('HC-12: la tolerancia en minutos permite la diferencia hasta ese tamaño', function () {
    $builder = fn (int $tolerance) => States::builder()
        ->setting('scheduling.weekly_hours_tolerance', $tolerance)
        ->subject(States::subject('S-A', theory: 3, practice: 2))
        ->group(States::group('A'))
        ->session('A', 1, '07:00', '10:00')
        ->session('A', 3, '07:00', '08:00')
        ->build();

    expect(weeklyHoursViolationsIn($builder(60), ValidationScope::groups('A')))->toBe([])
        ->and(weeklyHoursViolationsIn($builder(59), ValidationScope::groups('A')))->toHaveCount(1);
});

it('HC-12: un grupo sin sesiones, sin bloques de tiempo, cancelado o fuera del alcance no se revisa', function () {
    $state = States::builder()
        ->profile('C1', CampusProfile::fromArray(['uses_time_blocks' => false]))
        ->subject(States::subject('S-A'))
        ->subject(States::subject('S-B'))
        ->subject(States::subject('S-C'))
        ->subject(States::subject('S-D'))
        ->group(States::group('A'))
        ->group(States::group('B', campus: 'C1'))
        ->group(States::group('C', active: false))
        ->group(States::group('D'))
        ->session('B', 1, '07:00', '08:00')
        ->session('C', 1, '07:00', '08:00')
        ->session('D', 1, '07:00', '08:00')
        ->build();

    expect(weeklyHoursViolationsIn($state, ValidationScope::groups('A', 'B', 'C')))->toBe([])
        ->and(weeklyHoursViolationsIn($state, ValidationScope::groups('D')))->toHaveCount(1);
});
