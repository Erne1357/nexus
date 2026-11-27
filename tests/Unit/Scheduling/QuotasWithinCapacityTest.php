<?php

use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Rules\Constraints\QuotasWithinCapacity;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\State\StateQuota;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;
use Tests\Support\Scheduling\States;

/*
| HC-07: la suma de `max_seats` de los cupos por carrera de un grupo no puede exceder su capacidad.
*/

/**
 * @return list<Violation>
 */
function quotasWithinCapacityIn(ScheduleState $state, ValidationScope $scope): array
{
    return [...(new QuotasWithinCapacity)->check($state, $scope)];
}

it('HC-07: cupos que suman más que la capacidad dan un choque duro en el grupo', function () {
    $state = States::builder()
        ->subject(States::subject('S-A', 'Cálculo Diferencial'))
        ->group(States::group('A', capacity: 30, code: 'B'))
        ->quota(new StateQuota('A', 'ISC', 20, true))
        ->quota(new StateQuota('A', 'IIND', 15))
        ->build();

    $violations = quotasWithinCapacityIn($state, ValidationScope::groups('A'));

    expect($violations)->toHaveCount(1)
        ->and($violations[0])
        ->rule->toBe('HC-07')
        ->severity->toBe(ConflictSeverity::Hard)
        ->subjectType->toBe('group')
        ->subjectId->toBe('A')
        ->groupId->toBe('A')
        ->message->toBe('Los cupos de Cálculo Diferencial, grupo B suman 35 lugares y la capacidad es 30.')
        ->related->toBe(['seats' => 35, 'capacity' => 30]);
});

it('HC-07: cupos que suman justo la capacidad, o menos, no violan', function () {
    $state = States::builder()
        ->group(States::group('A', capacity: 30))
        ->group(States::group('B', capacity: 30))
        ->quota(new StateQuota('A', 'ISC', 20, true))
        ->quota(new StateQuota('A', 'IIND', 10))
        ->quota(new StateQuota('B', 'ISC', 5, true))
        ->build();

    expect(quotasWithinCapacityIn($state, ValidationScope::groups('A', 'B')))->toBe([]);
});

it('HC-07: ignora grupos cancelados y grupos fuera del alcance', function () {
    $state = States::builder()
        ->group(States::group('A', capacity: 10, active: false))
        ->group(States::group('B', capacity: 10))
        ->quota(new StateQuota('A', 'ISC', 20, true))
        ->quota(new StateQuota('B', 'ISC', 20, true))
        ->build();

    expect(quotasWithinCapacityIn($state, ValidationScope::groups('A')))->toBe([])
        ->and(quotasWithinCapacityIn($state, ValidationScope::groups('A', 'B')))->toHaveCount(1);
});
