<?php

use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Solver\Contract\ProposedSession;
use App\Modules\Solver\Contract\RunStatus;
use App\Modules\Solver\Contract\SoftWeights;
use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Contract\SolverOutput;
use App\Modules\Solver\Engines\PhpHeuristic\Builder;
use App\Modules\Solver\Engines\PhpHeuristic\FinalValidation;
use App\Modules\Solver\Engines\PhpHeuristic\LocalScore;
use App\Modules\Solver\Engines\PhpHeuristic\LocalSearch;
use App\Modules\Solver\Engines\PhpHeuristic\PhpHeuristicEngine;
use App\Modules\Solver\Evaluation\ContractEvaluator;
use Random\Engine\Mt19937;
use Random\Randomizer;

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
    $readings = [];
    $clock = function () use (&$readings): float {
        $readings[] = (float) count($readings) + 1;

        return end($readings);
    };

    $result = (new LocalSearch)->run($input, $builder, 3.5, $clock);
    $final = (new ContractEvaluator)->evaluate($input, localSearchFlat($result->sessions));
    $late = array_values(array_filter($readings, fn (float $reading): bool => $reading >= 3.5));

    expect($result->iterations)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(2)
        ->and($result->timedOut)->toBeTrue()
        ->and(count($late))->toBeLessThanOrEqual(3)
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

it('reporta el avance de la búsqueda en cada iteración, entre 60 y 90 y sin bajar, con el puntaje antes de validar', function () {
    $calls = [];
    $output = (new PhpHeuristicEngine)->solve(localSearchSwapInstance(), function (int $percent, ?int $score) use (&$calls): void {
        $calls[] = [$percent, $score];
    });
    $search = array_values(array_filter($calls, fn (array $call): bool => $call[0] >= 60 && $call[0] <= 90));

    expect($search)->not->toBe([])
        ->and($search[0])->toBe([60, 16])
        ->and(array_column($calls, 0))->toBe(collect($calls)->pluck(0)->sort()->values()->all())
        ->and(count($search))->toBeGreaterThanOrEqual($output->metrics->iterations + 1)
        ->and(end($search)[1])->toBe($output->softScore->total)
        ->and($output->metrics->iterations)->toBeGreaterThan(0)
        ->and(implode(' ', $output->log))->toContain('Búsqueda local:');
});

/**
 * Una entrada generada con semilla fija, al estilo de las de la demo pero chica: dos o tres campus (uno con bloques de
 * otro tamaño y otra hora de inicio, a veces uno virtual con sábado), docentes con preferidos, indisponibilidad y
 * `busy` desalineados, salones de varios tipos con dueño, bloqueo, `busy` y préstamos, grupos fijos, cohortes de plan
 * y de paquete, laboratorios, salones por defecto y pesos al azar.
 *
 * @return array<string, mixed>
 */
function localSearchRandomData(int $seed, int $groupCount): array
{
    $random = new Randomizer(new Mt19937($seed));
    $int = fn (int $min, int $max): int => $random->getInt($min, $max);
    $pick = fn (array $items): mixed => $items[$random->getInt(0, count($items) - 1)];
    $clock = fn (int $minutes): string => sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    $block = $pick([60, 30, 20, 45, 50]);
    $start = $pick([420, 450, 490]);
    $campuses = [
        ['code' => 'C1', 'block_minutes' => 60, 'day_start' => '07:00', 'day_end' => '15:00', 'days' => [1, 2, 3, 4, 5], 'uses_rooms' => true],
        ['code' => 'C2', 'block_minutes' => $block, 'day_start' => $clock($start), 'day_end' => $clock($start + intdiv(900 - $start, $block) * $block), 'days' => [1, 2, 3, 4, 5], 'uses_rooms' => true],
    ];

    if ($int(0, 2) === 0) {
        $campuses[] = ['code' => 'V1', 'block_minutes' => 60, 'day_start' => '07:00', 'day_end' => '13:00', 'days' => [1, 2, 3, 4, 5, 6], 'uses_rooms' => false];
    }

    $codes = array_column($campuses, 'code');
    $teacherCount = max(3, intdiv($groupCount, 4));
    $teachers = [];

    for ($i = 0; $i < $teacherCount; $i++) {
        $teacher = localSearchTeacher("t{$i}", ['remaining_hours' => 60, 'honorarios' => $int(0, 3) === 0]);

        if ($int(0, 1) === 1) {
            $from = 420 + $int(0, 12) * 30;
            $teacher['preferred'][] = ['day' => $int(1, 5), 'starts_at' => $clock($from), 'ends_at' => $clock(min(900, $from + $int(1, 4) * 60))];
        }

        if ($int(0, 1) === 1) {
            foreach ([1, 2, 3, 4] as $day) {
                $from = 420 + $int(0, 6) * 60;
                $teacher['preferred'][] = ['day' => $day, 'starts_at' => $clock($from), 'ends_at' => $clock($from + 180)];
            }
        }

        if ($int(0, 2) === 0) {
            $teacher['unavailable'][] = ['day' => $int(1, 5), 'starts_at' => '12:00', 'ends_at' => '15:00'];
        }

        for ($b = $int(0, 2); $b > 0; $b--) {
            $from = 420 + $int(0, 14) * 25;
            $slot = ['day' => $int(1, 5), 'starts_at' => $clock($from), 'ends_at' => $clock($from + $int(1, 3) * 35)];
            $teacher['busy'][] = $int(0, 1) === 1 ? $slot + ['campus_code' => $pick($codes)] : $slot;
        }

        $teachers[] = $teacher;
    }

    $rooms = [];

    foreach (array_filter($campuses, fn (array $campus): bool => $campus['uses_rooms']) as $campus) {
        for ($i = $int(2, max(3, intdiv($groupCount, 6))); $i > 0; $i--) {
            $room = localSearchRoom('r'.count($rooms), ['campus_code' => $campus['code'], 'type' => $pick(['aula', 'aula', 'aula', 'laboratorio', 'sala_computo', 'auditorio']),
                'capacity' => $int(25, 45), 'building_id' => 'b'.$int(0, 2), 'blocks' => [], 'busy' => []]);

            if ($int(0, 4) === 0) {
                $room['owner_department_id'] = 'd'.$int(1, 2);
            }

            if ($int(0, 3) === 0) {
                $room['blocks'][] = ['day' => $int(1, 5), 'starts_at' => '10:00', 'ends_at' => '12:00'];
            }

            if ($int(0, 3) === 0) {
                $from = 420 + $int(0, 10) * 20;
                $room['busy'][] = ['day' => $int(1, 5), 'starts_at' => $clock($from), 'ends_at' => $clock($from + 70)];
            }

            $rooms[] = $room;
        }
    }

    $groups = [];

    for ($i = 0; $i < $groupCount; $i++) {
        $campus = $pick($campuses);
        $semester = $int(1, 4);
        $hours = $pick([2, 3, 4, 4, 5, 5, 6]);
        $practice = $int(0, 2) === 0 ? $int(1, min(2, $hours)) : 0;
        $cohorts = $int(0, 3) > 0 ? ["plan:p1:{$semester}:{$campus['code']}"] : [];

        if ($int(0, 4) === 0) {
            $cohorts[] = 'plan:p1:'.($semester + 1).":{$campus['code']}";
        }

        if ($int(0, 4) === 0) {
            $cohorts[] = 'package:k'.$int(1, 2).':'.$int(1, 2);
        }

        $group = localSearchGroup(sprintf('g%03d', $i), ['campus_code' => $campus['code'], 'subject_code' => 'S'.$int(0, 6), 'department_id' => 'd'.$int(1, 2),
            'semester' => $semester, 'weekly_hours' => $hours, 'practice_hours' => $practice, 'needs_lab' => $practice > 0 && $int(0, 1) === 1,
            'capacity' => $int(20, 40), 'cohorts' => $cohorts, 'movable' => $int(0, 5) > 0, 'fixed_sessions' => []]);

        if ($int(0, 2) > 0) {
            $group['teacher_id'] = 't'.$int(0, $teacherCount - 1);
        }

        if (! $group['movable']) {
            $campusRooms = array_values(array_filter($rooms, fn (array $room): bool => $room['campus_code'] === $campus['code']));
            $first = $int(1, 3);
            $from = 420 + $int(0, 10) * 30;

            foreach ([$first, $first + 1] as $day) {
                $group['fixed_sessions'][] = ['day' => $day, 'starts_at' => $clock($from), 'ends_at' => $clock($from + 60),
                    'kind' => $campus['uses_rooms'] ? 'teoria' : 'virtual', 'room_id' => $campus['uses_rooms'] && $campusRooms !== [] ? $pick($campusRooms)['id'] : null];
            }
        }

        $groups[] = $group;
    }

    $defaults = [];

    for ($i = 0; $i < 4 && $rooms !== []; $i++) {
        $room = $pick($rooms);
        $defaults[] = ['subject_code' => 'S'.$int(0, 6), 'campus_code' => $room['campus_code'], 'room_id' => $room['id'], 'priority' => $int(0, 2)];
    }

    $agreements = [];

    foreach ($rooms as $room) {
        if (isset($room['owner_department_id']) && $int(0, 1) === 1) {
            $agreements[] = ['room_id' => $room['id'], 'to_department_id' => $room['owner_department_id'] === 'd1' ? 'd2' : 'd1', 'day' => $int(1, 5), 'starts_at' => '07:00', 'ends_at' => '11:00'];
        }
    }

    $weights = [];

    foreach (SoftWeights::RULES as $rule) {
        $weights[$rule] = $int(0, 3) === 0 ? 0 : $int(1, 10);
    }

    return [
        'run_id' => "semilla-{$seed}", 'campuses' => $campuses, 'travel_minutes' => $pick([30, 60, 90]), 'groups' => $groups, 'teachers' => $teachers,
        'rooms' => $rooms, 'agreements' => $agreements, 'subject_room_defaults' => $defaults, 'soft_weights' => $weights,
    ];
}

/**
 * Los bloques ocupados de los índices del constructor (sin el dueño, que depende del orden) y sus listas de ocupación.
 *
 * @return array<string, list<string>>
 */
function localSearchOccupancy(Builder $builder): array
{
    $reflection = new ReflectionClass($builder);
    $occupancy = [];

    foreach (['teacherAt', 'roomAt', 'cohortAt'] as $property) {
        $paths = [];
        $walk = function (array $level, string $path) use (&$walk, &$paths): void {
            foreach ($level as $key => $value) {
                is_array($value) ? $walk($value, "{$path}/{$key}") : $paths[] = "{$path}/{$key}";
            }
        };
        $walk($reflection->getProperty($property)->getValue($builder), '');
        sort($paths);
        $occupancy[$property] = $paths;
    }

    foreach (['teacherSpans', 'roomSpans', 'cohortSpans'] as $property) {
        $spans = [];

        foreach ($reflection->getProperty($property)->getValue($builder) as $owner => $days) {
            foreach ($days as $day => $list) {
                foreach ($list as $span) {
                    $spans[] = "{$owner}/{$day}/".json_encode($span);
                }
            }
        }

        sort($spans);
        $occupancy[$property] = $spans;
    }

    return $occupancy;
}

it('con entradas generadas (semilla fija), el puntaje incremental es el del evaluador, no aparecen choques y los índices quedan como recién construidos', function () {
    $evaluator = new ContractEvaluator;
    $accepted = 0;

    foreach (range(1, 40) as $seed) {
        $input = localSearchInput([], [], [], localSearchRandomData($seed, 8 + $seed % 19));
        $builder = new Builder($input);
        $build = $builder->run(INF, fn (): float => 0.0);
        [, $removed] = (new FinalValidation)->clean($input, $build->sessions, $build->placementOrder);

        foreach (array_keys($removed) as $groupId) {
            $builder->remove((string) $groupId);
        }

        $before = $evaluator->evaluate($input, localSearchFlat($builder->sessions()));
        $result = (new LocalSearch)->run($input, $builder, INF, fn (): float => 0.0);
        $after = $evaluator->evaluate($input, localSearchFlat($result->sessions));
        $rebuilt = new Builder($input);

        foreach ($builder->placements() as $groupId => $placement) {
            $rebuilt->assign((string) $groupId, $placement);
        }

        $keys = fn (array $violations): array => collect($violations)->map(fn ($violation): string => $violation->rule.'|'.implode(',', $violation->groupIds))->sort()->values()->all();
        $accepted += $result->acceptedMoves();

        expect($result->initialScore)->toBe($before->softScore->total, "semilla {$seed}: el puntaje inicial")
            ->and($result->finalScore)->toBe($after->softScore->total, "semilla {$seed}: el puntaje final")
            ->and($result->finalScore)->toBeLessThanOrEqual($result->initialScore)
            ->and(array_diff($keys($after->hardViolations), $keys($before->hardViolations)))->toBe([], "semilla {$seed}: choques nuevos")
            ->and(localSearchOccupancy($builder))->toBe(localSearchOccupancy($rebuilt), "semilla {$seed}: los índices");
    }

    expect($accepted)->toBeGreaterThan(0);
});

it('rechaza mover un grupo a una hora que rompe una regla dura, aunque le convenga (HC-01, HC-02, HC-03, HC-09, HC-10, HC-11)', function (string $rule, Closure $blocking) {
    $preferred = localSearchSlots([1, 2, 3, 4], '10:00', '11:00');
    $base = [
        'groups' => [localSearchGroup('g1', ['teacher_id' => 't1', 'cohorts' => ['plan:p001:1:C1']])],
        'teachers' => [localSearchTeacher('t1', ['preferred' => $preferred]), localSearchTeacher('t2')],
        'rooms' => [localSearchRoom('r1')],
        'agreements' => [],
    ];
    $tenOClock = function (array $parts): ?array {
        $input = localSearchInput($parts['groups'], $parts['teachers'], $parts['rooms'], ['agreements' => $parts['agreements']]);
        $builder = localSearchBuilt($input);
        $placement = $builder->placementOf('g1');
        $builder->release('g1');

        return $builder->placementAt('g1', $placement['option'], 3, $placement['tier']);
    };
    $parts = $blocking($base);
    $input = localSearchInput($parts['groups'], $parts['teachers'], $parts['rooms'], ['agreements' => $parts['agreements']]);
    $builder = localSearchBuilt($input);
    $placement = $builder->placementOf('g1');
    $builder->release('g1');
    [$moves] = $builder->alternatives('g1');
    $atTen = $builder->placementAt('g1', $placement['option'], 3, $placement['tier']);
    $builder->assign('g1', $placement);
    $result = (new LocalSearch)->run($input, $builder, INF, fn (): float => 0.0);

    expect($tenOClock($base))->not->toBeNull()
        ->and($atTen)->toBeNull()
        ->and(array_filter($moves, fn (array $move): bool => $move['start'] === 3))->toBe([])
        ->and(localSearchPatternOf($result->sessions['g1'], 'g1')[1])->not->toContain('10:00')
        ->and((new ContractEvaluator)->evaluate($input, localSearchFlat($result->sessions))->hardViolations)->toBe([]);
})->with([
    'HC-01: el docente ya da clase el lunes a las 10' => ['HC-01', fn (array $parts): array => array_replace($parts, [
        'teachers' => [localSearchTeacher('t1', ['preferred' => localSearchSlots([1, 2, 3, 4], '10:00', '11:00'), 'busy' => [['day' => 1, 'starts_at' => '10:00', 'ends_at' => '11:00', 'campus_code' => 'C1']]]), localSearchTeacher('t2')],
    ])],
    'HC-02: el único salón está ocupado el lunes a las 10' => ['HC-02', fn (array $parts): array => array_replace($parts, [
        'rooms' => [localSearchRoom('r1', ['busy' => [['day' => 1, 'starts_at' => '10:00', 'ends_at' => '11:00']]])],
    ])],
    'HC-03: un grupo fijo de su cohorte va el lunes a las 10' => ['HC-03', fn (array $parts): array => array_replace($parts, [
        'groups' => [...$parts['groups'], localSearchGroup('f1', ['teacher_id' => 't2', 'cohorts' => ['plan:p001:1:C1'], 'movable' => false,
            'fixed_sessions' => [['day' => 1, 'starts_at' => '10:00', 'ends_at' => '11:00', 'room_id' => 'r2', 'kind' => 'teoria']]])],
        'rooms' => [localSearchRoom('r1'), localSearchRoom('r2')],
    ])],
    'HC-09: el docente no está disponible el lunes a las 10' => ['HC-09', fn (array $parts): array => array_replace($parts, [
        'teachers' => [localSearchTeacher('t1', ['preferred' => localSearchSlots([1, 2, 3, 4], '10:00', '11:00'), 'unavailable' => [['day' => 1, 'starts_at' => '10:00', 'ends_at' => '11:00']]]), localSearchTeacher('t2')],
    ])],
    'HC-10: el préstamo del salón ajeno no cubre el lunes a las 10' => ['HC-10', fn (array $parts): array => array_replace($parts, [
        'rooms' => [localSearchRoom('r1', ['owner_department_id' => 'd002'])],
        'agreements' => [
            ...array_map(fn (int $day): array => ['room_id' => 'r1', 'to_department_id' => 'd001', 'day' => $day, 'starts_at' => '07:00', 'ends_at' => '14:00'], [2, 3, 4, 5]),
            ['room_id' => 'r1', 'to_department_id' => 'd001', 'day' => 1, 'starts_at' => '07:00', 'ends_at' => '10:00'],
            ['room_id' => 'r1', 'to_department_id' => 'd001', 'day' => 1, 'starts_at' => '11:00', 'ends_at' => '14:00'],
        ],
    ])],
    'HC-11: el único salón está bloqueado el lunes a las 10' => ['HC-11', fn (array $parts): array => array_replace($parts, [
        'rooms' => [localSearchRoom('r1', ['blocks' => [['day' => 1, 'starts_at' => '10:00', 'ends_at' => '11:00']]])],
    ])],
]);

it('rechaza el intercambio que pondría al otro grupo donde su docente no puede (HC-09)', function () {
    $cohort = ['plan:p001:1:C1'];
    $input = localSearchInput(
        [localSearchGroup('x', ['teacher_id' => 'tx', 'cohorts' => $cohort]), localSearchGroup('y', ['teacher_id' => 'ty', 'cohorts' => $cohort])],
        [
            localSearchTeacher('tx', ['preferred' => localSearchSlots([1, 2, 3, 4], '07:00', '08:00')]),
            localSearchTeacher('ty', ['preferred' => localSearchSlots([1, 2, 3, 4], '07:00', '09:00'),
                'unavailable' => [...localSearchSlots([1, 2, 3, 4, 5], '08:00', '09:00'), ...localSearchSlots([1, 2, 3, 4, 5], '10:00', '14:00')]]),
        ],
        [localSearchRoom('r1'), localSearchRoom('r2')],
    );
    $built = (new PhpHeuristicEngine(search: null))->solve($input);
    $searched = (new PhpHeuristicEngine)->solve($input);

    expect(localSearchPatternOf($built->sessions, 'y'))->toBe([[1, 2, 3, 4], ['07:00']])
        ->and(localSearchPatternOf($searched->sessions, 'y'))->toBe([[1, 2, 3, 4], ['07:00']])
        ->and(localSearchPatternOf($searched->sessions, 'x')[1])->not->toContain('07:00')
        ->and($searched->softScore->total)->toBe($built->softScore->total)
        ->and($searched->hardViolations)->toBe([]);
});

it('una excepción lanzada desde el avance durante la búsqueda sale de solve(): así se cancela una corrida', function () {
    expect(fn () => (new PhpHeuristicEngine)->solve(localSearchSwapInstance(), function (int $percent): void {
        if ($percent >= 60) {
            throw new RuntimeException('Corrida cancelada.');
        }
    }))->toThrow(RuntimeException::class, 'Corrida cancelada.');
});

it('mira el reloj en cada corrimiento del docente, para no pasarse del plazo dentro de una iteración', function () {
    $input = localSearchInput(
        [localSearchGroup('g1', ['teacher_id' => 't1']), localSearchGroup('g2', ['teacher_id' => 't1']), localSearchGroup('g3', ['teacher_id' => 't1'])],
        [localSearchTeacher('t1')],
        [localSearchRoom('r1')],
    );
    $reads = 0;
    $clock = function () use (&$reads): float {
        $reads++;

        return 0.0;
    };

    $result = (new LocalSearch)->run($input, localSearchBuilt($input), 1.0, $clock);

    expect($reads)->toBeGreaterThanOrEqual(1 + $result->iterations + 15);
});

it('con reloj real y un límite corto, lo que tarda no pasa del límite aunque la búsqueda no termine', function () {
    $input = localSearchInput([], [], [], [...localSearchRandomData(7, 150), 'time_limit_seconds' => 1]);
    $started = hrtime(true);
    $output = (new PhpHeuristicEngine(search: new LocalSearch(maxIdleIterations: PHP_INT_MAX)))->solve($input);
    $elapsed = (hrtime(true) - $started) / 1e9;

    expect($elapsed)->toBeLessThanOrEqual(1.0)
        ->and($output->metrics->elapsedSeconds)->toBeLessThanOrEqual(1.0)
        ->and($output->log)->toContain('La búsqueda local paró por el tiempo límite; se devuelve lo mejor que llevaba, validado.');
});
