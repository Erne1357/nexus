<?php

use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Solver\Contract\ProposedSession;
use App\Modules\Solver\Contract\RunStatus;
use App\Modules\Solver\Contract\SoftWeights;
use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Contract\SolverOutput;
use App\Modules\Solver\Engines\PhpHeuristic\Builder;
use App\Modules\Solver\Engines\PhpHeuristic\LocalScore;
use App\Modules\Solver\Engines\PhpHeuristic\LocalSearch;
use App\Modules\Solver\Engines\PhpHeuristic\PhpHeuristicEngine;
use App\Modules\Solver\Evaluation\ContractEvaluator;

/*
| La búsqueda local de la heurística en PHP (tarea 6.7; docs/arquitectura/solver.md, «Heurística PHP: búsqueda local»):
| después de la construcción mueve grupos enteros a otra hora (o a otro juego de días de su nivel o de uno mejor),
| intercambia las horas de dos grupos del mismo patrón y campus y cambia salones. Un movimiento entra solo si no crea
| choques duros y baja el puntaje blando, calculado de forma incremental con las mismas unidades que el evaluador. Nunca
| empeora el puntaje ni agrega una violación dura, para por tiempo o por iteraciones sin mejora y es determinista.
*/

/**
 * Un fixture compartido de tests/Fixtures/solver/v1, con lo que cambie.
 *
 * @param  array<string, mixed>  $changes
 */
function localSearchFixture(string $name, array $changes = []): SolverInput
{
    $data = json_decode(file_get_contents(__DIR__."/../../Fixtures/solver/v1/{$name}/input.json"), true, flags: JSON_THROW_ON_ERROR);

    return SolverInput::fromArray(array_merge($data, $changes));
}

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function localSearchGroup(string $id, array $changes = []): array
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
function localSearchTeacher(string $id, array $changes = []): array
{
    return array_merge(['id' => $id, 'remaining_hours' => 40, 'honorarios' => false, 'unavailable' => [], 'preferred' => [], 'busy' => []], $changes);
}

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function localSearchRoom(string $id, array $changes = []): array
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
function localSearchInput(array $groups, array $teachers, array $rooms, array $changes = []): SolverInput
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
 * Los mismos bloques en varios días.
 *
 * @param  list<int>  $days
 * @return list<array{day: int, starts_at: string, ends_at: string}>
 */
function localSearchSlots(array $days, string $from, string $to): array
{
    return array_map(fn (int $day): array => ['day' => $day, 'starts_at' => $from, 'ends_at' => $to], $days);
}

/**
 * La construcción de la entrada, lista para buscar.
 */
function localSearchBuilt(SolverInput $input): Builder
{
    $builder = new Builder($input);
    $builder->run(INF, fn (): float => 0.0);

    return $builder;
}

/**
 * Las sesiones de una salida por grupo.
 *
 * @return array<string, list<ProposedSession>>
 */
function localSearchByGroup(SolverOutput $output): array
{
    $byGroup = [];

    foreach ($output->sessions as $session) {
        $byGroup[$session->groupId][] = $session;
    }

    return $byGroup;
}

/**
 * @param  array<string, list<ProposedSession>>  $byGroup
 * @return list<ProposedSession>
 */
function localSearchFlat(array $byGroup): array
{
    return array_merge([], ...array_values($byGroup));
}

/**
 * Los días y las horas de inicio de un grupo.
 *
 * @param  list<ProposedSession>  $sessions
 * @return array{list<int>, list<string>}
 */
function localSearchPatternOf(array $sessions, string $groupId): array
{
    $mine = array_values(array_filter($sessions, fn (ProposedSession $session): bool => $session->groupId === $groupId));
    $days = array_values(array_unique(array_map(fn (ProposedSession $session): int => $session->day, $mine)));
    sort($days);

    return [$days, array_values(array_unique(array_map(fn (ProposedSession $session): string => $session->startsAt, $mine)))];
}

/**
 * Dos grupos de una misma cohorte: Y (su docente solo puede de 7 a 10 y prefiere de 7 a 9) se coloca primero y toma
 * las 7:00, así que X (su docente solo prefiere de 7 a 8) queda a las 8:00 fuera de sus preferidos (SC-04: 4 h × 4).
 * El óptimo conocido es X a las 7:00 y Y a las 8:00, con puntaje cero.
 */
function localSearchSwapInstance(): SolverInput
{
    $cohort = ['plan:p001:1:C1'];

    return localSearchInput(
        [localSearchGroup('x', ['teacher_id' => 'tx', 'cohorts' => $cohort]), localSearchGroup('y', ['teacher_id' => 'ty', 'cohorts' => $cohort])],
        [
            localSearchTeacher('tx', ['preferred' => localSearchSlots([1, 2, 3, 4], '07:00', '08:00')]),
            localSearchTeacher('ty', ['preferred' => localSearchSlots([1, 2, 3, 4], '07:00', '09:00'), 'unavailable' => localSearchSlots([1, 2, 3, 4, 5], '10:00', '14:00')]),
        ],
        [localSearchRoom('r1'), localSearchRoom('r2')],
    );
}

it('el puntaje incremental es el que da el evaluador, regla por regla', function (string $fixture, string $rule) {
    $sessions = (new PhpHeuristicEngine(search: null))->solve(localSearchFixture($fixture))->sessions;
    $weights = array_fill_keys(SoftWeights::RULES, 0);
    $weights[$rule] = 3;
    $input = localSearchFixture($fixture, ['soft_weights' => $weights]);
    $byGroup = [];

    foreach ($sessions as $session) {
        $byGroup[$session->groupId][] = $session;
    }

    expect((new LocalScore($input, $byGroup))->total())->toBe((new ContractEvaluator)->evaluate($input, $sessions)->softScore->total);
})->with(['tiny', 'small', 'medium', 'infeasible'])->with(SoftWeights::RULES);

it('el delta de un cambio de uno o dos grupos es la diferencia de puntaje que da el evaluador', function (string $fixture) {
    $input = localSearchFixture($fixture);
    $byGroup = localSearchByGroup((new PhpHeuristicEngine(search: null))->solve($input));
    $roomIds = array_map(fn ($room): string => $room->id, $input->rooms);
    $evaluator = new ContractEvaluator;
    $score = new LocalScore($input, $byGroup);
    $total = $evaluator->evaluate($input, localSearchFlat($byGroup))->softScore->total;
    $groupIds = array_keys($byGroup);

    expect($score->total())->toBe($total);

    foreach ($groupIds as $i => $groupId) {
        $changes = [];

        foreach ($i % 2 === 0 && isset($groupIds[$i + 1]) ? [$groupId, $groupIds[$i + 1]] : [$groupId] as $j => $changed) {
            $shift = (($i + $j) % 3 + 1) * 60 * (($i + $j) % 2 === 0 ? 1 : -1);
            $changes[$changed] = array_map(fn (ProposedSession $session, int $n): ProposedSession => new ProposedSession(
                $changed,
                $i % 4 === 3 ? 1 : $session->day,
                Minutes::format(Minutes::of($session->startsAt) + $shift + ($i % 4 === 3 ? $n * 60 : 0)),
                Minutes::format(Minutes::of($session->endsAt) + $shift + ($i % 4 === 3 ? $n * 60 : 0)),
                $session->roomId === null ? null : $roomIds[($i + $n) % count($roomIds)],
                $session->kind,
                true,
            ), $byGroup[$changed], array_keys($byGroup[$changed]));
        }

        $delta = $score->delta($changes);
        $byGroup = array_merge($byGroup, $changes);
        $next = $evaluator->evaluate($input, localSearchFlat($byGroup))->softScore->total;

        expect($delta)->toBe($next - $total);

        $score->apply($changes);
        $total = $next;
    }

    expect($score->total())->toBe($total);
})->with(['tiny', 'small', 'medium']);

it('en la instancia real de departamento, el puntaje incremental es el que da el evaluador', function () {
    $input = localSearchFixture('department-multicampus');
    $output = (new PhpHeuristicEngine(search: null))->solve($input);

    expect((new LocalScore($input, localSearchByGroup($output)))->total())->toBe($output->softScore->total);
})->group('solver-slow');

it('a partir de la construcción, la búsqueda nunca empeora el puntaje ni crea choques duros', function (string $fixture) {
    $input = localSearchFixture($fixture);
    $evaluator = new ContractEvaluator;
    $builder = localSearchBuilt($input);
    $before = $builder->sessions();
    $initial = $evaluator->evaluate($input, localSearchFlat($before));

    $result = (new LocalSearch)->run($input, $builder, INF, fn (): float => 0.0);
    $final = $evaluator->evaluate($input, localSearchFlat($result->sessions));

    expect($initial->hardViolations)->toBe([])
        ->and($final->hardViolations)->toBe([])
        ->and($final->softScore->total)->toBeLessThanOrEqual($initial->softScore->total)
        ->and($result->initialScore)->toBe($initial->softScore->total)
        ->and($result->finalScore)->toBe($final->softScore->total)
        ->and(array_keys($result->sessions))->toEqualCanonicalizing(array_keys($before))
        ->and($result->timedOut)->toBeFalse();
})->with(['tiny', 'small', 'medium', 'infeasible']);

it('con la búsqueda, el motor nunca da peor puntaje que la construcción sola, deja los mismos grupos sin programar y no deja choques duros', function (string $fixture) {
    $input = localSearchFixture($fixture);
    $built = (new PhpHeuristicEngine(search: null))->solve($input);
    $searched = (new PhpHeuristicEngine)->solve($input);

    expect($searched->softScore->total)->toBeLessThanOrEqual($built->softScore->total)
        ->and($searched->status)->toBe($built->status)
        ->and($searched->hardViolations)->toBe([])
        ->and((new ContractEvaluator)->evaluate($input, $searched->sessions)->hardViolations)->toBe([])
        ->and(array_map(fn ($entry): string => $entry->groupId, $searched->unscheduled))->toBe(array_map(fn ($entry): string => $entry->groupId, $built->unscheduled));
})->with(['tiny', 'small', 'medium', 'infeasible']);

it('encuentra el óptimo conocido de una instancia pequeña intercambiando las horas de dos grupos de la cohorte (SC-04)', function () {
    $input = localSearchSwapInstance();
    $built = (new PhpHeuristicEngine(search: null))->solve($input);
    $searched = (new PhpHeuristicEngine)->solve($input);

    expect($built->softScore->total)->toBe(16)
        ->and(localSearchPatternOf($built->sessions, 'x'))->toBe([[1, 2, 3, 4], ['08:00']])
        ->and($searched->softScore->total)->toBe(0)
        ->and($searched->status)->toBe(RunStatus::Feasible)
        ->and(localSearchPatternOf($searched->sessions, 'x'))->toBe([[1, 2, 3, 4], ['07:00']])
        ->and(localSearchPatternOf($searched->sessions, 'y'))->toBe([[1, 2, 3, 4], ['08:00']])
        ->and($searched->metrics->intermediateSolutions)->toBe(2)
        ->and(implode(' ', $searched->log))->toContain('1 de intercambiar')->toContain('de 16 a 0');
});

it('mueve un grupo entero, con su patrón y a la misma hora todos los días, a una hora mejor (SC-04, PS-02)', function () {
    $input = localSearchInput(
        [localSearchGroup('g1', ['teacher_id' => 't1'])],
        [localSearchTeacher('t1', ['preferred' => localSearchSlots([1, 2, 3, 4], '10:00', '11:00')])],
        [localSearchRoom('r1')],
    );
    $builder = localSearchBuilt($input);
    $placement = $builder->placements()['g1'];
    $builder->release('g1');
    $builder->assign('g1', $builder->placementAt('g1', $placement['option'], 0, $placement['tier']));

    $result = (new LocalSearch)->run($input, $builder, INF, fn (): float => 0.0);

    expect($result->initialScore)->toBe(16)
        ->and($result->finalScore)->toBe(0)
        ->and($result->accepted)->toBe(['move' => 1, 'swap' => 0, 'room' => 0, 'shift' => 0])
        ->and(localSearchPatternOf($result->sessions['g1'], 'g1'))->toBe([[1, 2, 3, 4], ['10:00']]);
});

it('cambia de salón para que el docente no cambie de edificio entre horas seguidas (SC-06)', function () {
    $input = localSearchInput(
        [
            localSearchGroup('g1', ['teacher_id' => 't1', 'capacity' => 35]),
            localSearchGroup('g2', ['teacher_id' => 't1', 'capacity' => 25, 'weekly_hours' => 5]),
        ],
        [localSearchTeacher('t1')],
        [localSearchRoom('r1', ['capacity' => 30]), localSearchRoom('r2', ['building_id' => 'b002'])],
    );
    $built = (new PhpHeuristicEngine(search: null))->solve($input);
    $searched = (new PhpHeuristicEngine)->solve($input);
    $rooms = fn (SolverOutput $output, string $groupId): array => array_values(array_unique(array_map(fn (ProposedSession $session): ?string => $session->roomId,
        array_filter($output->sessions, fn (ProposedSession $session): bool => $session->groupId === $groupId))));

    expect($built->softScore->byRule['SC-06'])->toBe(8)
        ->and($rooms($built, 'g2'))->toBe(['r1'])
        ->and($searched->softScore->total)->toBe(0)
        ->and($rooms($searched, 'g2'))->toBe(['r2'])
        ->and(localSearchPatternOf($searched->sessions, 'g2'))->toBe(localSearchPatternOf($built->sessions, 'g2'))
        ->and(implode(' ', $searched->log))->toContain('1 de cambiar salón');
});

it('desplaza todos los grupos de un docente cuando mover uno solo le abriría huecos (SC-04, SC-05)', function () {
    $weights = ['SC-01' => 10, 'SC-02' => 6, 'SC-03' => 5, 'SC-04' => 4, 'SC-05' => 10, 'SC-06' => 2, 'SC-07' => 3, 'SC-08' => 2, 'SC-09' => 2];
    $input = localSearchInput(
        [localSearchGroup('g1', ['teacher_id' => 't1']), localSearchGroup('g2', ['teacher_id' => 't1']), localSearchGroup('g3', ['teacher_id' => 't1'])],
        [localSearchTeacher('t1', ['preferred' => localSearchSlots([1, 2, 3, 4], '11:00', '14:00')])],
        [localSearchRoom('r1')],
        ['soft_weights' => $weights],
    );
    $builder = localSearchBuilt($input);

    foreach (['g1', 'g2', 'g3'] as $groupId) {
        $builder->release($groupId);
    }

    foreach (['g1' => 0, 'g2' => 1, 'g3' => 2] as $groupId => $start) {
        $placement = $builder->placementOf($groupId);
        $builder->assign($groupId, $builder->placementAt($groupId, $placement['option'], $start, $placement['tier']));
    }

    $result = (new LocalSearch)->run($input, $builder, INF, fn (): float => 0.0);
    $starts = array_map(fn (string $groupId): array => localSearchPatternOf($result->sessions[$groupId], $groupId)[1], ['g1', 'g2', 'g3']);

    expect($result->initialScore)->toBe(48)
        ->and($result->finalScore)->toBe(0)
        ->and($result->accepted['shift'])->toBe(1)
        ->and(array_merge(...$starts))->toBe(['11:00', '12:00', '13:00']);
});

it('no saca de su patrón a un grupo que ya está en él, aunque otros días le convengan más (PS-01, PS-04)', function () {
    $input = localSearchInput(
        [localSearchGroup('g1', ['teacher_id' => 't1'])],
        [localSearchTeacher('t1', ['preferred' => localSearchSlots([2, 3, 4, 5], '07:00', '14:00')])],
        [localSearchRoom('r1')],
    );
    $output = (new PhpHeuristicEngine)->solve($input);

    expect(localSearchPatternOf($output->sessions, 'g1')[0])->toBe([1, 2, 3, 4])
        ->and($output->softScore->total)->toBe(4);
});

it('respeta el tiempo límite: para cuando el reloj llega al plazo y devuelve lo mejor que lleva, sin choques', function () {
    $input = localSearchFixture('medium');
    $builder = localSearchBuilt($input);
    $initial = (new ContractEvaluator)->evaluate($input, localSearchFlat($builder->sessions()))->softScore->total;
    $now = 0.0;
    $clock = function () use (&$now): float {
        $now += 1.0;

        return $now;
    };

    $result = (new LocalSearch)->run($input, $builder, 3.5, $clock);
    $final = (new ContractEvaluator)->evaluate($input, localSearchFlat($result->sessions));

    expect($result->iterations)->toBe(2)
        ->and($result->timedOut)->toBeTrue()
        ->and($final->hardViolations)->toBe([])
        ->and($final->softScore->total)->toBe($result->finalScore)
        ->and($result->finalScore)->toBeLessThanOrEqual($initial);
});

it('con poco tiempo, la búsqueda no le quita tiempo a la construcción: coloca lo mismo que sin búsqueda', function () {
    $clock = function (): Closure {
        $now = 0.0;

        return function () use (&$now): float {
            $now += 1.0;

            return $now;
        };
    };
    $input = localSearchFixture('small', ['time_limit_seconds' => 4]);
    $built = (new PhpHeuristicEngine(clock: $clock(), search: null))->solve($input);
    $searched = (new PhpHeuristicEngine(clock: $clock()))->solve($input);

    expect(count($searched->sessions))->toBe(count($built->sessions))
        ->and(count($searched->unscheduled))->toBe(count($built->unscheduled))
        ->and($searched->hardViolations)->toBe([])
        ->and($searched->log)->toContain('No quedó tiempo para la búsqueda local: se devuelve lo construido, validado.');
});

it('para tras N iteraciones seguidas sin mejora; por omisión, una vuelta completa a los grupos', function () {
    $input = localSearchInput(
        [localSearchGroup('g1', ['teacher_id' => 't1']), localSearchGroup('g2', ['teacher_id' => 't2']), localSearchGroup('g3', ['teacher_id' => 't3'])],
        [localSearchTeacher('t1'), localSearchTeacher('t2'), localSearchTeacher('t3')],
        [localSearchRoom('r1'), localSearchRoom('r2'), localSearchRoom('r3')],
    );

    $default = (new LocalSearch)->run($input, localSearchBuilt($input), INF, fn (): float => 0.0);
    $limited = (new LocalSearch(maxIdleIterations: 2))->run($input, localSearchBuilt($input), INF, fn (): float => 0.0);

    expect($default->iterations)->toBe(3)
        ->and($default->finalScore)->toBe(0)
        ->and($limited->iterations)->toBe(2)
        ->and($limited->timedOut)->toBeFalse();
});

it('es determinista: la misma entrada da la misma salida', function (string $fixture) {
    $run = function () use ($fixture): array {
        $output = (new PhpHeuristicEngine)->solve(localSearchFixture($fixture))->toArray();
        unset($output['metrics']['elapsed_seconds']);

        return $output;
    };

    expect($run())->toBe($run());
})->with(['small', 'medium']);

it('reporta el avance de la búsqueda entre 60 y 90 con el mejor puntaje, y deja las iteraciones en las métricas', function () {
    $calls = [];
    $output = (new PhpHeuristicEngine)->solve(localSearchSwapInstance(), function (int $percent, ?int $score) use (&$calls): void {
        $calls[] = [$percent, $score];
    });
    $search = array_values(array_filter($calls, fn (array $call): bool => $call[0] >= 60 && $call[0] <= 90));

    expect($search)->not->toBe([])
        ->and($search[0])->toBe([60, 16])
        ->and(array_column($calls, 0))->toBe(array_values(array_unique(array_column($calls, 0))))
        ->and($output->metrics->iterations)->toBeGreaterThan(0)
        ->and(implode(' ', $output->log))->toContain('Búsqueda local:');
});
