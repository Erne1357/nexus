<?php

use App\Modules\Scheduling\Validation\SessionCandidate;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Solver\Contract\ContractException;
use App\Modules\Solver\Contract\HardViolation;
use App\Modules\Solver\Contract\ProposedSession;
use App\Modules\Solver\Contract\SessionKind;
use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Evaluation\ContractEvaluator;
use App\Modules\Solver\Evaluation\ContractState;

/*
| El adaptador del contrato del motor (tarea 6.5): arma la foto de las reglas de Scheduling con la entrada y unas
| sesiones propuestas, y da las violaciones duras y el puntaje blando con la forma de la salida. Corre sobre el fixture
| `medium` (docs/arquitectura/solver.md, «Fixtures compartidos»): tronco común fijo (g001, en las cohortes de dos
| planes), paquete (g007 y g008), docente con ocupación en C2 (t004), laboratorio prestado los martes (r003, ocupado de
| 10:00 a 11:00), salón ajeno sin préstamo (r006), C1 hasta las 15:00 y C2 hasta las 14:00.
*/

/**
 * La entrada `medium`, con los cambios que se le hagan a su JSON.
 *
 * @param  (Closure(array<string, mixed>): array<string, mixed>)|null  $change
 */
function evaluatorMediumInput(?Closure $change = null): SolverInput
{
    $data = json_decode(file_get_contents(__DIR__.'/../../Fixtures/solver/v1/medium/input.json'), true, flags: JSON_THROW_ON_ERROR);

    return SolverInput::fromArray($change === null ? $data : $change($data));
}

/**
 * Las sesiones propuestas de un grupo: [día, inicio, fin, salón] y, si es práctica, `practica` al final.
 *
 * @param  list<array{0: int, 1: string, 2: string, 3: string|null, 4?: string}>  $slots
 * @return list<ProposedSession>
 */
function evaluatorSessions(string $groupId, array $slots): array
{
    return array_map(fn (array $slot): ProposedSession => new ProposedSession($groupId, $slot[0], $slot[1], $slot[2], $slot[3], SessionKind::from($slot[4] ?? 'teoria'), true), $slots);
}

/**
 * Una solución completa de `medium` sin choques duros, armada a mano.
 *
 * @return list<ProposedSession>
 */
function evaluatorMediumSolution(): array
{
    return [
        ...evaluatorSessions('g002', [[1, '09:00', '10:00', 'r002'], [3, '09:00', '10:00', 'r002'], [4, '07:00', '08:00', 'r002'], [2, '08:00', '10:00', 'r003', 'practica']]),
        ...evaluatorSessions('g003', [[1, '11:00', '12:00', 'r002'], [2, '11:00', '12:00', 'r002'], [3, '11:00', '12:00', 'r002']]),
        ...evaluatorSessions('g004', [[3, '12:00', '13:00', 'r002'], [4, '09:00', '10:00', 'r002'], [5, '09:00', '10:00', 'r002']]),
        ...evaluatorSessions('g005', [[2, '07:00', '08:00', 'r003', 'practica'], [2, '12:00', '13:00', 'r003', 'practica'], [3, '09:00', '10:00', 'r001'], [4, '10:00', '11:00', 'r001'], [5, '10:00', '11:00', 'r001']]),
        ...evaluatorSessions('g006', [[4, '11:00', '12:00', 'r001'], [5, '11:00', '12:00', 'r001']]),
        ...evaluatorSessions('g007', [[1, '13:00', '14:00', 'r002'], [2, '13:00', '14:00', 'r002'], [3, '13:00', '14:00', 'r002']]),
        ...evaluatorSessions('g008', [[1, '14:00', '15:00', 'r001'], [2, '14:00', '15:00', 'r001'], [3, '14:00', '15:00', 'r001']]),
        ...evaluatorSessions('g009', [[2, '07:00', '08:00', 'r004'], [3, '07:00', '08:00', 'r004'], [4, '07:00', '08:00', 'r004']]),
        ...evaluatorSessions('g010', [[2, '10:00', '11:00', 'r001'], [3, '10:00', '11:00', 'r001'], [4, '12:00', '13:00', 'r001']]),
        ...evaluatorSessions('g011', [[1, '07:00', '08:00', 'r005'], [2, '07:00', '08:00', 'r005'], [3, '07:00', '08:00', 'r005'], [4, '07:00', '08:00', 'r005']]),
        ...evaluatorSessions('g012', [[1, '10:00', '11:00', 'r002'], [3, '10:00', '11:00', 'r002'], [4, '08:00', '09:00', 'r002']]),
    ];
}

it('sin sesiones propuestas no hay choques, y la solución armada a mano tampoco tiene', function () {
    $evaluator = new ContractEvaluator;

    expect($evaluator->evaluate(evaluatorMediumInput(), [])->hardViolations)->toBe([])
        ->and($evaluator->evaluate(evaluatorMediumInput(), evaluatorMediumSolution())->hardViolations)->toBe([]);
});

dataset('one contract scenario per hard rule', [
    'HC-01 con la ocupación del docente en C2' => [
        fn () => evaluatorSessions('g009', [[1, '08:00', '09:00', 'r004'], [2, '08:00', '09:00', 'r004'], [3, '08:00', '09:00', 'r004']]),
        ['g009' => ['HC-01']],
    ],
    'HC-02 con la ocupación del laboratorio' => [
        fn () => evaluatorSessions('g005', [[2, '07:00', '08:00', 'r002'], [4, '07:00', '08:00', 'r002'], [5, '07:00', '08:00', 'r002'], [2, '10:00', '12:00', 'r003', 'practica']]),
        ['g005' => ['HC-02']],
    ],
    'HC-03 con el tronco común fijo, que cuenta en los dos planes' => [
        fn () => evaluatorSessions('g004', [[1, '08:00', '09:00', 'r002'], [2, '08:00', '09:00', 'r002'], [4, '08:00', '09:00', 'r002']]),
        ['g001' => ['HC-03'], 'g004' => ['HC-03']],
    ],
    'HC-03 con el paquete (y HC-01, porque los dos son de t005)' => [
        fn () => [
            ...evaluatorSessions('g007', [[2, '10:00', '11:00', 'r002'], [4, '10:00', '11:00', 'r002'], [5, '10:00', '11:00', 'r002']]),
            ...evaluatorSessions('g008', [[2, '10:00', '11:00', 'r001'], [4, '11:00', '12:00', 'r001'], [5, '11:00', '12:00', 'r001']]),
        ],
        ['g007' => ['HC-01', 'HC-03'], 'g008' => ['HC-01', 'HC-03']],
    ],
    'HC-04 con la rejilla del campus del grupo (C2 acaba a las 14:00; C1, a las 15:00)' => [
        fn () => [
            ...evaluatorSessions('g011', [[1, '13:00', '14:00', 'r005'], [2, '13:00', '14:00', 'r005'], [3, '13:00', '15:00', 'r005']]),
            ...evaluatorSessions('g010', [[2, '14:00', '15:00', 'r002'], [3, '14:00', '15:00', 'r002'], [4, '14:00', '15:00', 'r002']]),
        ],
        ['g011' => ['HC-04']],
    ],
    'HC-05' => [
        fn () => evaluatorSessions('g007', [[2, '07:00', '08:00', 'r001'], [4, '07:00', '08:00', 'r001'], [5, '10:00', '11:00', 'r001']]),
        ['g007' => ['HC-05']],
    ],
    'HC-06' => [
        fn () => evaluatorSessions('g002', [[1, '10:00', '11:00', 'r002'], [3, '10:00', '11:00', 'r002'], [4, '10:00', '11:00', 'r002'], [4, '11:00', '13:00', 'r002', 'practica']]),
        ['g002' => ['HC-06']],
    ],
    'HC-09' => [
        fn () => evaluatorSessions('g012', [[5, '07:00', '08:00', 'r002'], [2, '12:00', '13:00', 'r002'], [3, '12:00', '13:00', 'r002']]),
        ['g012' => ['HC-09']],
    ],
    'HC-10 en un salón ajeno sin préstamo' => [
        fn () => evaluatorSessions('g006', [[2, '07:00', '09:00', 'r006']]),
        ['g006' => ['HC-10']],
    ],
    'HC-11' => [
        fn () => evaluatorSessions('g010', [[5, '07:00', '08:00', 'r001'], [2, '14:00', '15:00', 'r002'], [4, '14:00', '15:00', 'r002']]),
        ['g010' => ['HC-11']],
    ],
    'HC-12' => [
        fn () => evaluatorSessions('g011', [[1, '07:00', '08:00', 'r005'], [2, '07:00', '08:00', 'r005']]),
        ['g011' => ['HC-12']],
    ],
]);

it('cada regla dura choca con lo que trae la entrada, y solo en los grupos de la entrada', function (Closure $sessions, array $expected) {
    expect((new ContractEvaluator)->evaluate(evaluatorMediumInput(), $sessions())->rulesByGroup())->toEqualCanonicalizing($expected);
})->with('one contract scenario per hard rule');

it('HC-08: las horas que le quedan al docente son las de la entrada', function () {
    $tight = evaluatorMediumInput(function (array $data): array {
        $data['teachers'][4]['remaining_hours'] = 5;

        return $data;
    });

    expect((new ContractEvaluator)->evaluate(evaluatorMediumInput(), [])->rulesByGroup())->toBe([])
        ->and((new ContractEvaluator)->evaluate($tight, [])->rulesByGroup())->toEqualCanonicalizing(['g007' => ['HC-08'], 'g008' => ['HC-08']]);
});

it('cada choque entre dos grupos de la entrada sale una vez, con los dos grupos, y nunca con un grupo de contexto', function () {
    $pair = (new ContractEvaluator)->evaluate(evaluatorMediumInput(), evaluatorSessions('g004', [[1, '08:00', '09:00', 'r002'], [2, '08:00', '09:00', 'r002'], [4, '08:00', '09:00', 'r002']]));
    $busy = (new ContractEvaluator)->evaluate(evaluatorMediumInput(), evaluatorSessions('g009', [[1, '08:00', '09:00', 'r004'], [2, '08:00', '09:00', 'r004'], [3, '08:00', '09:00', 'r004']]));

    expect($pair->hardViolations)->toHaveCount(1)
        ->and($pair->hardViolations[0])->toBeInstanceOf(HardViolation::class)
        ->and($pair->hardViolations[0]->groupIds)->toEqualCanonicalizing(['g004', 'g001'])
        ->and($busy->hardViolations)->toHaveCount(1)
        ->and($busy->hardViolations[0]->groupIds)->toBe(['g009'])
        ->and($busy->hardViolations[0]->teacherId)->toBe('t004')
        ->and($busy->hardViolations[0]->rule)->toBe('HC-01');
});

it('solo corre las reglas duras activas de la entrada', function () {
    $withoutRooms = evaluatorMediumInput(function (array $data): array {
        $data['hard_rules'] = array_values(array_diff($data['hard_rules'], ['HC-02']));

        return $data;
    });
    $sessions = evaluatorSessions('g005', [[2, '07:00', '08:00', 'r002'], [4, '07:00', '08:00', 'r002'], [5, '07:00', '08:00', 'r002'], [2, '10:00', '12:00', 'r003', 'practica']]);

    expect((new ContractEvaluator)->evaluate($withoutRooms, $sessions)->hardViolations)->toBe([]);
});

it('rechaza una sesión propuesta de un grupo fijo o de uno que no está en la entrada', function (string $groupId, string $message) {
    expect(fn () => ContractState::from(evaluatorMediumInput(), evaluatorSessions($groupId, [[2, '07:00', '08:00', 'r002']])))
        ->toThrow(ContractException::class, "sessions[0].group_id: {$message}");
})->with([
    'fijo' => ['g001', 'el grupo no es movible'],
    'ajeno' => ['g999', 'el grupo no está en la entrada'],
]);

it('el puntaje blando usa los pesos de la entrada, sus cohortes, los preferidos y el traslado', function () {
    $evaluation = (new ContractEvaluator)->evaluate(evaluatorMediumInput(), [
        // t004 sale de C2 a las 9:00 (ocupación) y entra a C1 a las 9:00: un traslado justo (SC-09).
        ...evaluatorSessions('g010', [[1, '09:00', '10:00', 'r002'], [2, '09:00', '10:00', 'r002'], [3, '09:00', '10:00', 'r002']]),
        // t002 es de honorarios y prefiere de lunes a miércoles de 7:00 a 12:00: 3 h fuera (SC-04 y SC-08). El
        // lunes, el alumno tipo de p002 tiene el tronco común de 7:00 a 9:00 y esta clase a las 12:00: 3 h de hueco (SC-01).
        ...evaluatorSessions('g004', [[1, '12:00', '13:00', 'r002'], [4, '09:00', '10:00', 'r002'], [5, '09:00', '10:00', 'r002']]),
    ]);

    expect($evaluation->hardViolations)->toBe([])
        ->and($evaluation->softScore->byRule)->toBe(['SC-01' => 30, 'SC-02' => 0, 'SC-03' => 0, 'SC-04' => 12, 'SC-05' => 0, 'SC-06' => 0, 'SC-07' => 0, 'SC-08' => 6, 'SC-09' => 2])
        ->and($evaluation->softScore->total)->toBe(50);
});

it('SC-03 premia el salón por defecto de la materia en su campus', function () {
    $input = evaluatorMediumInput(function (array $data): array {
        $data['soft_weights'] = ['SC-03' => 5];

        return $data;
    });
    $practiceOnly = fn (string $room): array => evaluatorSessions('g002', [[1, '09:00', '10:00', $room], [3, '09:00', '10:00', $room], [4, '07:00', '08:00', $room], [2, '08:00', '10:00', 'r003', 'practica']]);

    expect((new ContractEvaluator)->evaluate($input, $practiceOnly('r002'))->softScore->total)->toBe(15)
        ->and((new ContractEvaluator)->evaluate($input, $practiceOnly('r003'))->softScore->total)->toBe(0);
});

it('las sesiones actuales de los movibles se evalúan como una propuesta sin cambios', function () {
    $input = evaluatorMediumInput(function (array $data): array {
        $data['groups'][8]['current_sessions'] = [
            ['day' => 1, 'starts_at' => '08:00', 'ends_at' => '09:00', 'room_id' => 'r004', 'kind' => 'teoria'],
            ['day' => 2, 'starts_at' => '08:00', 'ends_at' => '09:00', 'room_id' => 'r004', 'kind' => 'teoria'],
            ['day' => 3, 'starts_at' => '08:00', 'ends_at' => '09:00', 'room_id' => 'r004', 'kind' => 'teoria'],
        ];

        return $data;
    });

    $current = ContractState::currentSessions($input);

    expect($current)->toHaveCount(3)
        ->and($current[0])->groupId->toBe('g009')->changed->toBeFalse()
        ->and((new ContractEvaluator)->evaluate($input, $current)->rulesByGroup())->toBe(['g009' => ['HC-01']]);
});

it('pregunta «¿y si…?» sobre una foto ya armada, con withSession y el alcance de un grupo', function () {
    $evaluator = new ContractEvaluator;
    $state = ContractState::from(evaluatorMediumInput(), evaluatorSessions('g009', [[2, '08:00', '09:00', 'r004'], [3, '08:00', '09:00', 'r004']]));

    $clash = $state->withState($state->state->withSession('g009', new SessionCandidate(1, '08:00', '09:00', 'r004')));
    $free = $state->withState($state->state->withSession('g009', new SessionCandidate(4, '08:00', '09:00', 'r004')));

    expect($evaluator->evaluateState($clash, ValidationScope::groups('g009'))->rulesByGroup())->toBe(['g009' => ['HC-01']])
        ->and($evaluator->evaluateState($free, ValidationScope::groups('g009'))->hardViolations)->toBe([])
        ->and($state->isInputGroup('g009'))->toBeTrue()
        ->and($state->isInputGroup(ContractState::CONTEXT.'teacher:t004:C2'))->toBeFalse();
});

it('HC-03: un tronco común movible choca con un fijo del otro plan en el que cuenta', function () {
    $input = evaluatorMediumInput(function (array $data): array {
        $data['groups'][] = [
            'id' => 'g013', 'campus_code' => 'C1', 'subject_code' => 'MED-0013', 'department_id' => 'd002', 'owner_career_id' => 'c002',
            'study_plan_id' => 'p002', 'semester' => 1, 'weekly_hours' => 2, 'practice_hours' => 0, 'needs_lab' => false, 'capacity' => 30,
            'cohorts' => ['plan:p002:1:C1'], 'fixed_sessions' => [['day' => 4, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room_id' => 'r001', 'kind' => 'teoria']],
            'candidate_room_ids' => [], 'movable' => false, 'current_sessions' => [],
        ];

        return $data;
    });

    expect((new ContractEvaluator)->evaluate($input, evaluatorSessions('g003', [[4, '08:00', '09:00', 'r002'], [1, '10:00', '11:00', 'r002'], [2, '10:00', '11:00', 'r002']]))->rulesByGroup())
        ->toEqualCanonicalizing(['g003' => ['HC-03'], 'g013' => ['HC-03']]);
});

it('dos choques distintos entre los mismos dos grupos no se juntan aunque sus sesiones sean candidatas sin id', function () {
    $state = ContractState::from(evaluatorMediumInput(), []);
    $state = $state->withState($state->state
        ->withSession('g007', new SessionCandidate(1, '10:00', '11:00', 'r002'))->withSession('g007', new SessionCandidate(3, '10:00', '11:00', 'r002'))
        ->withSession('g008', new SessionCandidate(1, '10:00', '11:00', 'r001'))->withSession('g008', new SessionCandidate(3, '10:00', '11:00', 'r001')));

    $teacher = array_values(array_filter((new ContractEvaluator)->hardViolations($state), fn (HardViolation $violation): bool => $violation->rule === 'HC-01'));

    expect(count($teacher))->toBeGreaterThanOrEqual(2);
});

it('una materia con la misma clave y otras horas en otro grupo no le cambia las horas al primero', function () {
    $input = evaluatorMediumInput(function (array $data): array {
        $data['groups'][11]['subject_code'] = 'MED-0010';
        $data['groups'][11]['weekly_hours'] = 5;

        return $data;
    });

    expect((new ContractEvaluator)->evaluate($input, evaluatorSessions('g010', [[2, '14:00', '15:00', 'r002'], [3, '14:00', '15:00', 'r002'], [4, '14:00', '15:00', 'r002']]))->hardViolations)->toBe([]);
});

it('HC-12 usa la tolerancia de la entrada (`weekly_hours_tolerance`)', function () {
    $tolerant = fn (int $minutes): SolverInput => evaluatorMediumInput(function (array $data) use ($minutes): array {
        $data['weekly_hours_tolerance'] = $minutes;

        return $data;
    });
    $threeOfFour = evaluatorSessions('g011', [[1, '07:00', '08:00', 'r005'], [2, '07:00', '08:00', 'r005'], [3, '07:00', '08:00', 'r005']]);

    expect((new ContractEvaluator)->evaluate($tolerant(0), $threeOfFour)->rulesByGroup())->toBe(['g011' => ['HC-12']])
        ->and((new ContractEvaluator)->evaluate($tolerant(60), $threeOfFour)->hardViolations)->toBe([]);
});

it('una clave de paquete sin campus (`package:<id>:<n>`) ya no da paquete', function () {
    $input = evaluatorMediumInput(function (array $data): array {
        $data['groups'][6]['cohorts'] = ['package:k001:1'];
        $data['groups'][7]['cohorts'] = ['package:k001:1'];
        unset($data['groups'][7]['teacher_id']);

        return $data;
    });
    $sessions = [
        ...evaluatorSessions('g007', [[2, '10:00', '11:00', 'r002'], [4, '10:00', '11:00', 'r002'], [5, '10:00', '11:00', 'r002']]),
        ...evaluatorSessions('g008', [[2, '10:00', '11:00', 'r001'], [4, '11:00', '12:00', 'r001'], [5, '11:00', '12:00', 'r001']]),
    ];

    expect(ContractState::from($input, [])->state->group('g007')->packageId)->toBeNull()
        ->and((new ContractEvaluator)->evaluate($input, $sessions)->hardViolations)->toBe([]);
});

/**
 * `medium` con un semestre más (p001, 5, C1): g020 movible y g021 fijo, únicos de su materia, y MED-0022 con dos grupos
 * fijos de otro departamento (g022 el lunes de 10:00 a 12:00 y g023 el martes de 7:00 a 9:00), con las claves que da
 * Cohorts: `plan:` en los únicos y `combination:` en los únicos y en g022.
 */
function evaluatorCombinationInput(bool $withCombination = true): SolverInput
{
    return evaluatorMediumInput(function (array $data) use ($withCombination): array {
        $group = fn (string $id, string $subject, array $cohorts, array $fixed): array => [
            'id' => $id, 'campus_code' => 'C1', 'subject_code' => $subject, 'department_id' => $fixed === [] ? 'd001' : 'd002', 'owner_career_id' => 'c001',
            'study_plan_id' => 'p001', 'semester' => 5, 'weekly_hours' => 2, 'practice_hours' => 0, 'needs_lab' => false, 'capacity' => 30,
            'cohorts' => array_values(array_filter($cohorts, fn (string $key): bool => $withCombination || ! str_starts_with($key, 'combination:'))),
            'fixed_sessions' => $fixed, 'candidate_room_ids' => [], 'movable' => $fixed === [], 'current_sessions' => [],
        ];
        $at = fn (int $day, string $start, string $end): array => ['day' => $day, 'starts_at' => $start, 'ends_at' => $end, 'room_id' => 'r001', 'kind' => 'teoria'];

        $data['groups'][] = $group('g020', 'MED-0020', ['plan:p001:5:C1', 'combination:p001:5:C1'], []);
        $data['groups'][] = $group('g021', 'MED-0021', ['plan:p001:5:C1', 'combination:p001:5:C1'], [$at(3, '07:00', '09:00')]);
        $data['groups'][] = $group('g022', 'MED-0022', ['combination:p001:5:C1'], [$at(1, '10:00', '12:00')]);
        $data['groups'][] = $group('g023', 'MED-0022', [], [$at(2, '07:00', '09:00')]);

        return $data;
    });
}

it('HC-03: con la combinación del alumno tipo en la entrada, el evaluador ve el núcleo sin combinación igual que el validador', function () {
    $clash = evaluatorSessions('g020', [[1, '10:00', '11:00', 'r002'], [2, '07:00', '08:00', 'r002']]);
    $fits = evaluatorSessions('g020', [[1, '07:00', '08:00', 'r002'], [2, '10:00', '11:00', 'r002']]);

    expect((new ContractEvaluator)->evaluate(evaluatorCombinationInput(), $clash)->rulesByGroup())
        ->toEqualCanonicalizing(['g020' => ['HC-03'], 'g022' => ['HC-03'], 'g023' => ['HC-03']])
        ->and((new ContractEvaluator)->evaluate(evaluatorCombinationInput(), $fits)->hardViolations)->toBe([])
        ->and((new ContractEvaluator)->evaluate(evaluatorCombinationInput(withCombination: false), $clash)->hardViolations)->toBe([]);
});

it('SC-01 no cuenta la clave `combination:`: el puntaje blando es el mismo con ella y sin ella', function () {
    $sessions = evaluatorSessions('g020', [[1, '07:00', '08:00', 'r002'], [2, '10:00', '11:00', 'r002']]);

    expect((new ContractEvaluator)->evaluate(evaluatorCombinationInput(), $sessions)->softScore)
        ->toEqual((new ContractEvaluator)->evaluate(evaluatorCombinationInput(withCombination: false), $sessions)->softScore);
});

it('HC-10: un préstamo con `group_id` solo le sirve a ese grupo; sin él, a todo el departamento', function () {
    $input = fn (?string $groupId): SolverInput => evaluatorMediumInput(function (array $data) use ($groupId): array {
        if ($groupId !== null) {
            $data['agreements'][0]['group_id'] = $groupId;
        }

        return $data;
    });
    $sessions = [
        ...evaluatorSessions('g002', [[2, '08:00', '10:00', 'r003', 'practica']]),
        ...evaluatorSessions('g005', [[2, '07:00', '08:00', 'r003', 'practica']]),
    ];
    $hc10 = fn (SolverInput $input): array => array_keys(array_filter((new ContractEvaluator)->evaluate($input, $sessions)->rulesByGroup(), fn (array $rules): bool => in_array('HC-10', $rules, true)));

    expect($hc10($input(null)))->toBe([])
        ->and($hc10($input('g002')))->toBe(['g005'])
        ->and($hc10($input('g005')))->toBe(['g002']);
});
