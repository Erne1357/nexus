<?php

use App\Modules\Solver\Contract\ProposedSession;
use App\Modules\Solver\Contract\RunStatus;
use App\Modules\Solver\Contract\SessionKind;
use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Contract\SolverOutput;
use App\Modules\Solver\Engines\PhpHeuristic\FinalValidation;
use App\Modules\Solver\Engines\PhpHeuristic\PhpHeuristicEngine;
use App\Modules\Solver\Evaluation\ContractEvaluator;

/*
| La heurística en PHP, construcción voraz (tarea 6.6; docs/arquitectura/solver.md, «Heurística PHP: construcción»):
| ordena los grupos movibles, los coloca en sesiones de una hora, una por día y a la misma hora (4 h de lunes a jueves,
| 5 de lunes a viernes, 6 con sábado; confirmado el 17 de noviembre de 2026), con índices de ocupación que reflejan
| HC-01 a HC-13, y valida al final con el evaluador: nunca devuelve choques duros en los movibles. Lo que no cabe queda
| sin programar con su motivo.
*/

/**
 * Un grupo movible de la entrada, con lo que cambie.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function heuristicGroup(string $id, array $changes = []): array
{
    return array_merge([
        'id' => $id,
        'campus_code' => 'C1',
        'subject_code' => "MAT-{$id}",
        'department_id' => 'd001',
        'owner_career_id' => 'c001',
        'study_plan_id' => 'p001',
        'semester' => 1,
        'weekly_hours' => 4,
        'practice_hours' => 0,
        'needs_lab' => false,
        'capacity' => 30,
        'cohorts' => [],
        'movable' => true,
    ], $changes);
}

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function heuristicTeacher(string $id, array $changes = []): array
{
    return array_merge(['id' => $id, 'remaining_hours' => 40, 'honorarios' => false, 'unavailable' => [], 'preferred' => [], 'busy' => []], $changes);
}

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function heuristicRoom(string $id, array $changes = []): array
{
    return array_merge(['id' => $id, 'campus_code' => 'C1', 'type' => 'aula', 'capacity' => 40, 'building_id' => 'b001'], $changes);
}

/**
 * Una entrada de un campus (C1, de 7:00 a 14:00 de lunes a viernes, bloques de 60 min), con lo que cambie.
 *
 * @param  list<array<string, mixed>>  $groups
 * @param  list<array<string, mixed>>  $teachers
 * @param  list<array<string, mixed>>  $rooms
 * @param  array<string, mixed>  $changes
 */
function heuristicInput(array $groups, array $teachers, array $rooms, array $changes = []): SolverInput
{
    return SolverInput::fromArray(array_merge([
        'version' => 1,
        'run_id' => 'corrida-1',
        'term' => ['code' => '20271'],
        'modality' => ['code' => 'PRES'],
        'campuses' => [['code' => 'C1', 'block_minutes' => 60, 'day_start' => '07:00', 'day_end' => '14:00', 'days' => [1, 2, 3, 4, 5], 'uses_rooms' => true]],
        'time_limit_seconds' => 10,
        'scope' => ['kind' => 'department', 'id' => 'd001'],
        'travel_minutes' => 60,
        'groups' => $groups,
        'teachers' => $teachers,
        'rooms' => $rooms,
        'agreements' => [],
        'subject_room_defaults' => [],
        'hard_rules' => ['HC-01', 'HC-02', 'HC-03', 'HC-04', 'HC-05', 'HC-06', 'HC-07', 'HC-08', 'HC-09', 'HC-10', 'HC-11', 'HC-12', 'HC-13'],
        'soft_weights' => ['SC-01' => 10, 'SC-02' => 6, 'SC-03' => 5, 'SC-04' => 4, 'SC-05' => 3, 'SC-06' => 2, 'SC-07' => 3, 'SC-08' => 2, 'SC-09' => 2],
    ], $changes));
}

/**
 * Un fixture compartido de tests/Fixtures/solver/v1.
 */
function heuristicFixture(string $name): SolverInput
{
    return SolverInput::fromArray(json_decode(file_get_contents(__DIR__."/../../Fixtures/solver/v1/{$name}/input.json"), true, flags: JSON_THROW_ON_ERROR));
}

/**
 * Las sesiones de un grupo en la salida, por día.
 *
 * @return array<int, list<ProposedSession>>
 */
function heuristicSessionsByDay(SolverOutput $output, string $groupId): array
{
    $byDay = [];

    foreach ($output->sessions as $session) {
        if ($session->groupId === $groupId) {
            $byDay[$session->day][] = $session;
        }
    }

    ksort($byDay);

    return $byDay;
}

/**
 * Los motivos de un grupo sin programar, en un solo texto.
 */
function heuristicReasonsOf(SolverOutput $output, string $groupId): string
{
    foreach ($output->unscheduled as $entry) {
        if ($entry->groupId === $groupId) {
            return implode(' | ', $entry->reasons);
        }
    }

    return '';
}

/**
 * Los días y la hora de inicio de las sesiones de un grupo.
 *
 * @return array{list<int>, list<string>}
 */
function heuristicPatternOf(SolverOutput $output, string $groupId): array
{
    $byDay = heuristicSessionsByDay($output, $groupId);

    return [array_keys($byDay), array_values(array_unique(array_map(fn (array $sessions): string => $sessions[0]->startsAt, $byDay)))];
}

it('responde una salida válida del contrato y la registra como heurística que revisa reglas duras', function () {
    $engine = new PhpHeuristicEngine;
    $input = heuristicInput([heuristicGroup('g1', ['teacher_id' => 't1'])], [heuristicTeacher('t1')], [heuristicRoom('r1')]);
    $output = $engine->solve($input);

    expect($engine->key())->toBe('heuristic')
        ->and($engine->label())->toBe('Heurística (PHP)')
        ->and($engine->checksHardRules())->toBeTrue()
        ->and($engine->supportedVersions())->toBe([1])
        ->and(SolverOutput::fromArray($output->toArray())->toArray())->toBe($output->toArray())
        ->and($output->runId)->toBe('corrida-1')
        ->and($output->status)->toBe(RunStatus::Feasible)
        ->and($output->metrics->engine)->toBe('heuristic')
        ->and($output->metrics->iterations)->toBeGreaterThan(0)
        ->and($output->metrics->intermediateSolutions)->toBe(1);
});

it('pone 4 h de lunes a jueves, 5 de lunes a viernes y 6 con sábado, una hora por día y a la misma hora (PS-01, PS-02)', function () {
    $input = heuristicInput(
        [heuristicGroup('g4', ['weekly_hours' => 4, 'teacher_id' => 't1']), heuristicGroup('g5', ['weekly_hours' => 5, 'teacher_id' => 't2']), heuristicGroup('g6', ['weekly_hours' => 6, 'teacher_id' => 't3'])],
        [heuristicTeacher('t1'), heuristicTeacher('t2'), heuristicTeacher('t3')],
        [heuristicRoom('r1'), heuristicRoom('r2'), heuristicRoom('r3')],
        ['campuses' => [['code' => 'C1', 'block_minutes' => 60, 'day_start' => '07:00', 'day_end' => '14:00', 'days' => [1, 2, 3, 4, 5, 6], 'uses_rooms' => true]]],
    );
    $output = (new PhpHeuristicEngine)->solve($input);

    expect($output->status)->toBe(RunStatus::Feasible)
        ->and(heuristicPatternOf($output, 'g4')[0])->toBe([1, 2, 3, 4])
        ->and(heuristicPatternOf($output, 'g5')[0])->toBe([1, 2, 3, 4, 5])
        ->and(heuristicPatternOf($output, 'g6')[0])->toBe([1, 2, 3, 4, 5, 6]);

    foreach (['g4', 'g5', 'g6'] as $groupId) {
        expect(heuristicPatternOf($output, $groupId)[1])->toHaveCount(1);

        foreach (heuristicSessionsByDay($output, $groupId) as $sessions) {
            expect($sessions)->toHaveCount(1)
                ->and($sessions[0]->endsAt)->toBe(date('H:i', strtotime($sessions[0]->startsAt) + 3600))
                ->and($sessions[0]->changed)->toBeTrue();
        }
    }
});

it('con bloques de 30 minutos, cada hora son dos bloques seguidos (PS-06)', function () {
    $input = heuristicInput([heuristicGroup('g1', ['teacher_id' => 't1'])], [heuristicTeacher('t1')], [heuristicRoom('r1')], [
        'campuses' => [['code' => 'C1', 'block_minutes' => 30, 'day_start' => '07:00', 'day_end' => '14:00', 'days' => [1, 2, 3, 4, 5], 'uses_rooms' => true]],
    ]);
    $output = (new PhpHeuristicEngine)->solve($input);

    expect($output->status)->toBe(RunStatus::Feasible)
        ->and(heuristicPatternOf($output, 'g1')[0])->toBe([1, 2, 3, 4])
        ->and(array_map(fn (ProposedSession $session): int => strtotime($session->endsAt) - strtotime($session->startsAt), $output->sessions))->each->toBe(3600);
});

it('acomoda al docente con disponibilidad estrecha en la única hora que tiene (HC-09)', function () {
    $closed = [];

    foreach ([1, 2, 3, 4] as $day) {
        $closed[] = ['day' => $day, 'starts_at' => '07:00', 'ends_at' => '10:00'];
        $closed[] = ['day' => $day, 'starts_at' => '11:00', 'ends_at' => '14:00'];
    }

    $closed[] = ['day' => 5, 'starts_at' => '07:00', 'ends_at' => '14:00'];

    $input = heuristicInput(
        [heuristicGroup('g1', ['teacher_id' => 't1']), heuristicGroup('g2', ['teacher_id' => 't2'])],
        [heuristicTeacher('t1', ['unavailable' => $closed]), heuristicTeacher('t2')],
        [heuristicRoom('r1')],
    );
    $output = (new PhpHeuristicEngine)->solve($input);

    expect($output->status)->toBe(RunStatus::Feasible)
        ->and(heuristicPatternOf($output, 'g1'))->toBe([[1, 2, 3, 4], ['10:00']])
        ->and(heuristicPatternOf($output, 'g2')[1])->not->toBe(['10:00']);
});

it('pone las horas de práctica en el laboratorio libre y la teoría en el aula (HC-06, PS-05)', function () {
    $labBusy = [];

    foreach ([1, 3, 5] as $day) {
        $labBusy[] = ['day' => $day, 'starts_at' => '07:00', 'ends_at' => '14:00'];
    }

    $input = heuristicInput(
        [heuristicGroup('g1', ['weekly_hours' => 5, 'practice_hours' => 2, 'needs_lab' => true, 'teacher_id' => 't1'])],
        [heuristicTeacher('t1')],
        [heuristicRoom('r1'), heuristicRoom('lab', ['type' => 'laboratorio', 'busy' => $labBusy])],
    );
    $output = (new PhpHeuristicEngine)->solve($input);
    $practice = array_values(array_filter($output->sessions, fn (ProposedSession $session): bool => $session->kind->value === 'practica'));

    expect($output->status)->toBe(RunStatus::Feasible)
        ->and(heuristicPatternOf($output, 'g1')[0])->toBe([1, 2, 3, 4, 5])
        ->and(array_map(fn (ProposedSession $session): array => [$session->day, $session->roomId], $practice))->toBe([[2, 'lab'], [4, 'lab']])
        ->and(array_unique(array_map(fn (ProposedSession $session): string => $session->roomId, array_filter($output->sessions, fn (ProposedSession $session): bool => $session->kind->value === 'teoria'))))->toBe([0 => 'r1']);
});

it('separa los grupos de un paquete y deja sin programar al que ya no cabe, con su motivo (HC-03)', function () {
    $mornings = [];

    foreach ([1, 2, 3, 4, 5] as $day) {
        $mornings[] = ['day' => $day, 'starts_at' => '09:00', 'ends_at' => '14:00'];
    }

    $package = ['package:k1:C1:1'];
    $input = heuristicInput(
        [
            heuristicGroup('g1', ['teacher_id' => 't1', 'cohorts' => $package]),
            heuristicGroup('g2', ['teacher_id' => 't2', 'cohorts' => $package]),
            heuristicGroup('g3', ['teacher_id' => 't3', 'cohorts' => $package]),
        ],
        [heuristicTeacher('t1', ['unavailable' => $mornings]), heuristicTeacher('t2', ['unavailable' => $mornings]), heuristicTeacher('t3', ['unavailable' => $mornings])],
        [heuristicRoom('r1'), heuristicRoom('r2'), heuristicRoom('r3')],
    );
    $output = (new PhpHeuristicEngine)->solve($input);
    $starts = array_map(fn (string $groupId): array => heuristicPatternOf($output, $groupId)[1], ['g1', 'g2', 'g3']);
    $placed = array_values(array_filter($starts, fn (array $start): bool => $start !== []));

    expect($output->status)->toBe(RunStatus::Partial)
        ->and($placed)->toHaveCount(2)
        ->and(array_merge(...$placed))->toEqualCanonicalizing(['07:00', '08:00'])
        ->and($output->unscheduled)->toHaveCount(1)
        ->and(implode(' ', $output->unscheduled[0]->reasons))->toContain('HC-03')->toContain('paquete #1')
        ->and($output->hardViolations)->toBe([])
        ->and((new ContractEvaluator)->evaluate($input, $output->sessions)->hardViolations)->toBe([]);
});

it('usa el salón ajeno solo en las horas del préstamo aceptado (HC-10)', function () {
    $loans = array_map(fn (int $day): array => ['room_id' => 'r1', 'to_department_id' => 'd001', 'day' => $day, 'starts_at' => '09:00', 'ends_at' => '10:00'], [1, 2, 3, 4]);
    $input = heuristicInput(
        [heuristicGroup('g1', ['teacher_id' => 't1']), heuristicGroup('g2', ['teacher_id' => 't2', 'department_id' => 'd003'])],
        [heuristicTeacher('t1'), heuristicTeacher('t2')],
        [heuristicRoom('r1', ['owner_department_id' => 'd002'])],
        ['agreements' => $loans],
    );
    $output = (new PhpHeuristicEngine)->solve($input);

    expect(heuristicPatternOf($output, 'g1'))->toBe([[1, 2, 3, 4], ['09:00']])
        ->and(array_unique(array_map(fn (ProposedSession $session): ?string => $session->roomId, $output->sessions)))->toBe(['r1'])
        ->and(heuristicReasonsOf($output, 'g2'))->toContain('HC-10');
});

it('dice por qué no programa un grupo sin salón con lugar, sin laboratorio o con horas que la rejilla no da exactas (HC-05, HC-06, PS-06)', function () {
    $input = heuristicInput(
        [
            heuristicGroup('big', ['capacity' => 60]),
            heuristicGroup('lab', ['weekly_hours' => 5, 'practice_hours' => 2, 'needs_lab' => true]),
            heuristicGroup('c2', ['campus_code' => 'C2']),
        ],
        [],
        [heuristicRoom('r1'), heuristicRoom('r2', ['campus_code' => 'C2'])],
        ['campuses' => [
            ['code' => 'C1', 'block_minutes' => 60, 'day_start' => '07:00', 'day_end' => '14:00', 'days' => [1, 2, 3, 4, 5], 'uses_rooms' => true],
            ['code' => 'C2', 'block_minutes' => 50, 'day_start' => '07:00', 'day_end' => '13:40', 'days' => [1, 2, 3, 4, 5], 'uses_rooms' => true],
        ]],
    );
    $output = (new PhpHeuristicEngine)->solve($input);

    expect($output->status)->toBe(RunStatus::Partial)
        ->and($output->sessions)->toBe([])
        ->and(heuristicReasonsOf($output, 'big'))->toStartWith('HC-05')->toContain('60 alumnos')
        ->and(heuristicReasonsOf($output, 'lab'))->toStartWith('HC-06')
        ->and(heuristicReasonsOf($output, 'c2'))->toStartWith('HC-12')->toContain('bloques de 50 min');
});

it('reporta los grupos que no caben en la instancia infactible, con el motivo, y programa los demás', function () {
    $output = (new PhpHeuristicEngine)->solve(heuristicFixture('infeasible'));
    $unscheduled = array_map(fn ($entry): string => $entry->groupId, $output->unscheduled);

    expect($output->status)->toBe(RunStatus::Partial)
        ->and($output->hardViolations)->toBe([])
        ->and(count($unscheduled))->toBeGreaterThanOrEqual(2)
        ->and(array_diff($unscheduled, ['g001', 'g002', 'g003']))->toBe([])
        ->and(heuristicSessionsByDay($output, 'g004'))->not->toBe([])
        ->and(heuristicSessionsByDay($output, 'g005'))->not->toBe([]);

    foreach ($unscheduled as $groupId) {
        expect(heuristicReasonsOf($output, $groupId))->toContain('HC-09');
    }
});

it('si el patrón no cabe, junta dos horas el mismo día antes de dejar el grupo sin programar (PS-04)', function () {
    $output = (new PhpHeuristicEngine)->solve(heuristicFixture('small'));
    $byDay = heuristicSessionsByDay($output, 'g003');

    expect($output->status)->toBe(RunStatus::Feasible)
        ->and(array_keys($byDay))->not->toContain(5)
        ->and(array_sum(array_map('count', $byDay)))->toBe(5)
        ->and(max(array_map('count', $byDay)))->toBe(2)
        ->and($output->log)->toContain('1 grupo quedó con dos horas el mismo día porque su patrón no cupo (supuesto por confirmar).');
});

it('respeta el tiempo límite: devuelve lo que lleva, validado, y lo demás queda sin programar', function () {
    $now = 0.0;
    $clock = function () use (&$now): float {
        $now += 1.0;

        return $now;
    };
    $input = SolverInput::fromArray(array_merge(heuristicFixture('small')->toArray(), ['time_limit_seconds' => 4]));
    $output = (new PhpHeuristicEngine(clock: $clock))->solve($input);

    expect($output->status)->toBe(RunStatus::Partial)
        ->and($output->hardViolations)->toBe([])
        ->and($output->unscheduled)->not->toBe([])
        ->and($output->unscheduled[0]->reasons[0])->toContain('tiempo límite')
        ->and($output->log)->toContain('Se acabó el tiempo límite (4 s) antes de terminar: lo colocado se validó y lo demás queda sin programar.');
});

it('prefiere la hora y el salón actuales cuando no empeoran nada, y no los marca como cambiados (PS-07)', function () {
    $current = array_map(fn (int $day): array => ['day' => $day, 'starts_at' => '10:00', 'ends_at' => '11:00', 'room_id' => 'r2', 'kind' => 'teoria'], [1, 2, 3, 4]);
    $input = heuristicInput(
        [heuristicGroup('g1', ['teacher_id' => 't1', 'current_sessions' => $current])],
        [heuristicTeacher('t1')],
        [heuristicRoom('r1'), heuristicRoom('r2')],
    );
    $output = (new PhpHeuristicEngine)->solve($input);

    expect(heuristicPatternOf($output, 'g1'))->toBe([[1, 2, 3, 4], ['10:00']])
        ->and(array_map(fn (ProposedSession $session): array => [$session->roomId, $session->changed], $output->sessions))->each->toBe(['r2', false]);
});

it('la validación final quita al grupo colocado después de un choque duro que el evaluador encuentra, y deja HC-08', function () {
    $input = heuristicInput(
        [heuristicGroup('g1', ['teacher_id' => 't1']), heuristicGroup('g2', ['teacher_id' => 't1'])],
        [heuristicTeacher('t1', ['remaining_hours' => 7])],
        [heuristicRoom('r1'), heuristicRoom('r2')],
    );
    $week = fn (string $groupId, string $roomId): array => array_map(fn (int $day): ProposedSession => new ProposedSession($groupId, $day, '07:00', '08:00', $roomId, SessionKind::Theory, true), [1, 2, 3, 4]);

    [$sessions, $removed, $evaluation, $evaluations] = (new FinalValidation)->clean($input, ['g1' => $week('g1', 'r1'), 'g2' => $week('g2', 'r2')], ['g1' => 0, 'g2' => 1]);

    expect(array_keys($sessions))->toBe(['g1'])
        ->and(array_keys($removed))->toBe(['g2'])
        ->and($removed['g2'][0])->toStartWith('HC-01: ')
        ->and($evaluations)->toBe(2)
        ->and(array_map(fn ($violation): string => $violation->rule, $evaluation->hardViolations))->toBe(['HC-08', 'HC-08']);
});

it('la validación final quita solo al grupo que resuelve los choques, no a cada vecino', function () {
    $input = heuristicInput(
        [heuristicGroup('a', ['teacher_id' => 't1']), heuristicGroup('b', ['teacher_id' => 't1']), heuristicGroup('c', ['teacher_id' => 't2'])],
        [heuristicTeacher('t1'), heuristicTeacher('t2')],
        [heuristicRoom('r1'), heuristicRoom('r2')],
    );
    $week = fn (string $groupId, string $roomId): array => array_map(fn (int $day): ProposedSession => new ProposedSession($groupId, $day, '07:00', '08:00', $roomId, SessionKind::Theory, true), [1, 2, 3, 4]);

    [$sessions, $removed, $evaluation] = (new FinalValidation)->clean($input, ['a' => $week('a', 'r1'), 'b' => $week('b', 'r2'), 'c' => $week('c', 'r2')], ['a' => 0, 'b' => 1, 'c' => 2]);

    expect(array_keys($sessions))->toBe(['a', 'c'])
        ->and(array_keys($removed))->toBe(['b'])
        ->and(implode(' ', $removed['b']))->toContain('HC-01')->toContain('HC-02')
        ->and($evaluation->hardViolations)->toBe([]);
});

it('la validación final reporta su avance en cada pasada y, pasado el plazo, quita de una vez a todos los grupos de los choques', function () {
    $input = heuristicInput(
        [heuristicGroup('a', ['teacher_id' => 't1']), heuristicGroup('b', ['teacher_id' => 't1']), heuristicGroup('c', ['teacher_id' => 't2'])],
        [heuristicTeacher('t1'), heuristicTeacher('t2')],
        [heuristicRoom('r1'), heuristicRoom('r2')],
    );
    $week = fn (string $groupId, string $roomId): array => array_map(fn (int $day): ProposedSession => new ProposedSession($groupId, $day, '07:00', '08:00', $roomId, SessionKind::Theory, true), [1, 2, 3, 4]);

    $now = 0.0;
    $clock = function () use (&$now): float {
        $now += 3.0;

        return $now;
    };
    $calls = [];
    $progress = function (int $percent, ?int $score) use (&$calls): void {
        $calls[] = [$percent, $score];
    };

    [$sessions, $removed, $evaluation, $evaluations] = (new FinalValidation)->clean($input, ['a' => $week('a', 'r1'), 'b' => $week('b', 'r2'), 'c' => $week('c', 'r2')], ['a' => 0, 'b' => 1, 'c' => 2], $clock, 2.0, $progress);

    expect($sessions)->toBe([])
        ->and(array_keys($removed))->toEqualCanonicalizing(['a', 'b', 'c'])
        ->and($evaluations)->toBe(2)
        ->and(array_column($calls, 0))->toBe([91, 92])
        ->and(end($calls)[1])->toBe($evaluation->softScore->total);
});

it('coloca al docente que se pasa de horas y reporta HC-08 como violación de la entrada (PS-08)', function () {
    $input = heuristicInput([heuristicGroup('g1', ['teacher_id' => 't1'])], [heuristicTeacher('t1', ['remaining_hours' => 3])], [heuristicRoom('r1')]);
    $output = (new PhpHeuristicEngine)->solve($input);

    expect($output->status)->toBe(RunStatus::Partial)
        ->and(heuristicPatternOf($output, 'g1')[0])->toBe([1, 2, 3, 4])
        ->and(array_map(fn ($violation): string => $violation->rule, $output->hardViolations))->toBe(['HC-08'])
        ->and(implode(' ', $output->log))->toContain('HC-08: 1 docente(s)');
});

it('reporta su avance hasta 100 con el puntaje blando, sin bajar nunca', function () {
    $calls = [];
    $output = (new PhpHeuristicEngine)->solve(heuristicFixture('medium'), function (int $percent, ?int $score) use (&$calls): void {
        $calls[] = [$percent, $score];
    });

    expect($calls)->not->toBe([])
        ->and(array_column($calls, 0))->toBe(collect($calls)->pluck(0)->sort()->values()->all())
        ->and($calls)->toContain([91, $output->softScore->total])
        ->and(end($calls))->toBe([100, $output->softScore->total]);
});

it('en un campus sin salones las sesiones son virtuales y sin salón', function () {
    $input = heuristicInput([heuristicGroup('g1', ['teacher_id' => 't1'])], [heuristicTeacher('t1')], [], [
        'campuses' => [['code' => 'C1', 'block_minutes' => 60, 'day_start' => '07:00', 'day_end' => '14:00', 'days' => [1, 2, 3, 4, 5], 'uses_rooms' => false]],
    ]);
    $output = (new PhpHeuristicEngine)->solve($input);

    expect($output->status)->toBe(RunStatus::Feasible)
        ->and(array_map(fn (ProposedSession $session): array => [$session->roomId, $session->kind->value], $output->sessions))->each->toBe([null, 'virtual']);
});

it('responde con error a una versión del contrato que no entiende', function () {
    $input = heuristicInput([heuristicGroup('g1')], [], [heuristicRoom('r1')]);
    $other = new SolverInput(2, $input->runId, $input->termCode, $input->modalityCode, $input->campuses, $input->timeLimitSeconds, $input->scope,
        $input->travelMinutes, $input->groups, $input->teachers, $input->rooms, $input->agreements, $input->subjectRoomDefaults, $input->hardRules,
        $input->softWeights, $input->options);
    $output = (new PhpHeuristicEngine)->solve($other);

    expect($output->status)->toBe(RunStatus::Error)
        ->and($output->sessions)->toBe([])
        ->and($output->log[0])->toContain('versión 2');
});
