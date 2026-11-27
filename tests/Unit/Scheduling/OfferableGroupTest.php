<?php

use App\Modules\Scheduling\Rules\Constraints\OfferableGroup;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;
use Tests\Support\Scheduling\States;

/*
| HC-13: solo se pueden crear grupos de materias activas de planes activos, en carreras ofrecidas en el campus.
*/

/**
 * @return list<Violation>
 */
function offerableGroupViolationsIn(ScheduleState $state, ValidationScope $scope): array
{
    return [...(new OfferableGroup)->check($state, $scope)];
}

it('HC-13: un grupo de materia activa, plan activo y carrera ofrecida no viola', function () {
    $state = States::builder()
        ->subject(States::subject('S-A'))
        ->group(States::group('A'))
        ->activePlan('P1')
        ->offering('ISC', 'C2')
        ->build();

    expect(offerableGroupViolationsIn($state, ValidationScope::groups('A')))->toBe([]);
});

it('HC-13: cada razón da su propio choque duro', function () {
    $state = States::builder()
        ->subject(States::subject('S-A', 'Cálculo', active: false))
        ->group(States::group('A'))
        ->build();

    $violations = offerableGroupViolationsIn($state, ValidationScope::groups('A'));

    expect($violations)->toHaveCount(3)
        ->and($violations[0])
        ->rule->toBe('HC-13')
        ->subjectType->toBe('group')
        ->subjectId->toBe('A')
        ->groupId->toBe('A')
        ->message->toBe('Cálculo, grupo A es de una materia inactiva.')
        ->and($violations[1]->message)->toBe('Cálculo, grupo A es de un plan de estudios que no está activo.')
        ->and($violations[2]->message)->toBe('Cálculo, grupo A es de la carrera ISC, que no se ofrece en el campus del grupo.');
});

it('HC-13: solo falla la razón que no se cumple', function () {
    $state = States::builder()
        ->subject(States::subject('S-A'))
        ->group(States::group('A'))
        ->activePlan('P1')
        ->build();

    $violations = offerableGroupViolationsIn($state, ValidationScope::groups('A'));

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->related)->toMatchArray(['reason' => 'career_not_offered', 'career_id' => 'ISC', 'campus_id' => 'C2']);
});

it('HC-13: un grupo cancelado o fuera del alcance no se revisa', function () {
    $state = States::builder()
        ->subject(States::subject('S-A', active: false))
        ->group(States::group('A', active: false))
        ->group(States::group('B'))
        ->build();

    expect(offerableGroupViolationsIn($state, ValidationScope::groups('A')))->toBe([])
        ->and(offerableGroupViolationsIn($state, ValidationScope::groups('C')))->toBe([]);
});
