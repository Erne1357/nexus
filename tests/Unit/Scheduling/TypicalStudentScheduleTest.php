<?php

use App\Modules\Scheduling\Rules\Constraints\TypicalStudentSchedule;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;
use Tests\Support\Scheduling\States;

/*
| HC-03: dos grupos de materias del mismo semestre reticular del mismo plan y campus no deben empalmarse si son los
| únicos grupos de esas materias para esa carrera: el alumno tipo debe tener al menos una combinación sin empalme.
*/

/**
 * @return list<Violation>
 */
function typicalStudentViolations(ScheduleState $state, ValidationScope $scope): array
{
    return [...(new TypicalStudentSchedule)->check($state, $scope)];
}

it('HC-03: dos materias de un solo grupo que se empalman en el mismo semestre dan un choque duro en cada grupo', function () {
    $state = States::builder()
        ->subject(States::subject('CAL', 'Cálculo Diferencial'))
        ->subject(States::subject('QUI', 'Química'))
        ->group(States::group('A', subject: 'CAL', semester: 1, code: 'B'))
        ->group(States::group('B', subject: 'QUI', semester: 1, code: 'C'))
        ->session('A', 1, '08:00', '10:00')
        ->session('B', 1, '09:00', '11:00')
        ->build();

    $violations = typicalStudentViolations($state, ValidationScope::groups('A'));

    expect($violations)->toHaveCount(2)
        ->and(array_map(fn (Violation $violation): ?string => $violation->groupId, $violations))->toBe(['A', 'B'])
        ->and($violations[0])
        ->rule->toBe('HC-03')
        ->subjectType->toBe('group')
        ->subjectId->toBe('A')
        ->message->toBe('Se empalma con Química, grupo C el lunes de 09:00 a 11:00, la única opción de su materia en el semestre 1: el alumno tipo no puede llevar las dos.')
        ->and($violations[0]->related)->toMatchArray(['study_plan_id' => 'P1', 'semester' => 1, 'other_group_id' => 'B']);
});

it('HC-03: si la materia tiene otro grupo que no choca, el alumno tipo tiene combinación', function () {
    $state = States::builder()
        ->group(States::group('A', subject: 'CAL'))
        ->group(States::group('A2', subject: 'CAL'))
        ->group(States::group('B', subject: 'QUI'))
        ->session('A', 1, '08:00', '10:00')
        ->session('A2', 2, '08:00', '10:00')
        ->session('B', 1, '09:00', '11:00')
        ->build();

    expect(typicalStudentViolations($state, ValidationScope::load($state, 'C2')))->toBe([]);
});

it('HC-03: otro semestre, otro plan, otro campus, un grupo cancelado o uno sin sesiones no cuentan', function () {
    $state = States::builder()
        ->group(States::group('A', subject: 'CAL'))
        ->group(States::group('B', subject: 'QUI', semester: 2))
        ->group(States::group('C', subject: 'FIS', plan: 'P2'))
        ->group(States::group('D', subject: 'ETI', campus: 'C1'))
        ->group(States::group('E', subject: 'ALG', active: false))
        ->group(States::group('F', subject: 'PRO'))
        ->session('A', 1, '08:00', '10:00')
        ->session('B', 1, '08:00', '10:00')
        ->session('C', 1, '08:00', '10:00')
        ->session('D', 1, '08:00', '10:00')
        ->session('E', 1, '08:00', '10:00')
        ->build();

    expect(typicalStudentViolations($state, ValidationScope::groups('A', 'B', 'C', 'D', 'E', 'F')))->toBe([]);
});

it('HC-03: sin choques de grupos únicos pero sin combinación, marca los grupos de las materias que se estorban', function () {
    $state = States::builder()
        ->subject(States::subject('A', 'Álgebra'))
        ->subject(States::subject('B', 'Biología'))
        ->subject(States::subject('C', 'Cálculo'))
        ->group(States::group('a1', subject: 'A'))
        ->group(States::group('a2', subject: 'A'))
        ->group(States::group('b1', subject: 'B'))
        ->group(States::group('b2', subject: 'B'))
        ->group(States::group('c1', subject: 'C'))
        ->session('a1', 1, '07:00', '08:00')
        ->session('a2', 1, '08:00', '09:00')
        ->session('b1', 1, '07:00', '08:00')
        ->session('b2', 1, '08:00', '09:00')
        ->session('c1', 1, '07:00', '09:00')
        ->build();

    $violations = typicalStudentViolations($state, ValidationScope::groups('c1'));

    expect(array_map(fn (Violation $violation): ?string => $violation->groupId, $violations))->toEqualCanonicalizing(['b1', 'b2', 'c1'])
        ->and($violations[0]->message)->toBe('En el semestre 1 el alumno tipo no tiene una combinación sin empalmes entre Biología y Cálculo.')
        ->and($violations[0]->related['subjects'])->toEqualCanonicalizing(['B', 'C'])
        ->and($violations[0]->related['groups'])->toEqualCanonicalizing(['b1', 'b2', 'c1']);
});

it('HC-03: solo revisa los semestres de los grupos del alcance', function () {
    $state = States::builder()
        ->group(States::group('A', subject: 'CAL', semester: 3))
        ->group(States::group('B', subject: 'QUI', semester: 3))
        ->group(States::group('C', subject: 'FIS', semester: 4))
        ->session('A', 1, '08:00', '10:00')
        ->session('B', 1, '08:00', '10:00')
        ->build();

    expect(typicalStudentViolations($state, ValidationScope::groups('C')))->toBe([])
        ->and(typicalStudentViolations($state, ValidationScope::groups('B')))->toHaveCount(2);
});
