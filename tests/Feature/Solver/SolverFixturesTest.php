<?php

use App\Modules\Solver\Contract\RunStatus;
use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Contract\SolverOutput;
use App\Modules\Solver\Engines\EngineRegistry;
use App\Modules\Solver\Engines\FixedEngine;
use App\Modules\Solver\Engines\SolverEngine;

/*
| Los fixtures compartidos del motor (tarea 6.11; docs/arquitectura/solver.md, «Fixtures compartidos y pruebas»): todo
| motor registrado corre sobre cada instancia de tests/Fixtures/solver/v1. A todos se les exige una salida válida del
| contrato, dentro del tiempo límite y dentro de la rejilla de cada campus; solo a los que declaran revisar las reglas
| duras (`checksHardRules()`), además, lo que dice `expected.json` de la instancia (qué grupos se programan completos,
| si hay solución). `expected.json` describe lo que logra un motor correcto, no una salida exacta. Las instancias
| grandes llevan el grupo `solver-slow` para poder saltarlas en el CI de PR.
*/

/** Las instancias rápidas, que corren en cada PR. */
function solverFixtureNames(): array
{
    $names = array_map('basename', glob(__DIR__.'/../../Fixtures/solver/v1/*', GLOB_ONLYDIR));

    return array_values(array_diff($names, ['department-multicampus']));
}

/**
 * @return array<string, mixed>
 */
function solverFixtureJson(string $fixture, string $file): array
{
    return json_decode(file_get_contents(__DIR__.'/../../Fixtures/solver/v1/'.$fixture.'/'.$file), true, flags: JSON_THROW_ON_ERROR);
}

function solverMinutes(string $clock): int
{
    [$hours, $minutes] = array_map('intval', explode(':', $clock));

    return $hours * 60 + $minutes;
}

/**
 * Corre el motor sobre la instancia y devuelve los problemas que encuentra (vacía si cumple): lo que se le exige a todos
 * y, con `checksHardRules()`, también lo esperado. El segundo valor es lo que tardó, en segundos.
 *
 * @return array{list<string>, float}
 */
function solverFixtureProblems(SolverEngine $engine, string $fixture): array
{
    $label = "motor «{$engine->key()}» sobre «{$fixture}»";
    $input = SolverInput::fromArray(solverFixtureJson($fixture, 'input.json'));
    $expected = solverFixtureJson($fixture, 'expected.json');

    $started = hrtime(true);
    $raw = $engine->solve($input);
    $elapsed = (hrtime(true) - $started) / 1e9;

    $output = SolverOutput::fromArray($raw->toArray());
    $problems = [];

    if ($output->runId !== $input->runId) {
        $problems[] = 'no devolvió el run_id de la entrada';
    }

    if ($elapsed > $input->timeLimitSeconds) {
        $problems[] = sprintf('pasó del límite de tiempo (%.2f s de %d s)', $elapsed, $input->timeLimitSeconds);
    }

    $groups = [];
    foreach ($input->groups as $group) {
        $groups[$group->id] = $group;
    }
    $grids = [];
    foreach ($input->campuses as $grid) {
        $grids[$grid->code] = $grid;
    }
    $roomCampus = [];
    foreach ($input->rooms as $room) {
        $roomCampus[$room->id] = $room->campusCode;
    }

    $hours = [];
    foreach ($output->sessions as $session) {
        $group = $groups[$session->groupId] ?? null;

        if ($group === null) {
            $problems[] = "sesión de un grupo que no está en la entrada ({$session->groupId})";

            continue;
        }

        $grid = $grids[$group->campusCode];
        $start = solverMinutes($session->startsAt);
        $end = solverMinutes($session->endsAt);

        if (! in_array($session->day, $grid->days, true)) {
            $problems[] = "{$session->groupId}: día {$session->day} fuera del campus {$group->campusCode}";
        }

        if ($start < solverMinutes($grid->dayStart) || $end > solverMinutes($grid->dayEnd)) {
            $problems[] = "{$session->groupId}: fuera de la jornada ({$session->startsAt}-{$session->endsAt})";
        }

        if (($start - solverMinutes($grid->dayStart)) % $grid->blockMinutes !== 0 || ($end - $start) % $grid->blockMinutes !== 0) {
            $problems[] = "{$session->groupId}: no alineada a bloques de {$grid->blockMinutes} min";
        }

        if ($grid->usesRooms && ($session->roomId === null || ($roomCampus[$session->roomId] ?? null) !== $group->campusCode)) {
            $problems[] = "{$session->groupId}: sin salón del campus {$group->campusCode}";
        }

        $hours[$session->groupId] = ($hours[$session->groupId] ?? 0) + intdiv($end - $start, 60);
    }

    if ($engine->checksHardRules() && $expected['feasible'] !== null) {
        $unscheduled = array_map(fn ($entry): string => $entry->groupId, $output->unscheduled);

        foreach ($expected['must_schedule'] as $groupId) {
            if (in_array($groupId, $unscheduled, true)) {
                $problems[] = "{$groupId} debía programarse y quedó sin programar";
            } elseif (($hours[$groupId] ?? 0) !== $groups[$groupId]->weeklyHours) {
                $problems[] = "{$groupId} no tiene sus horas semanales (HC-12)";
            }
        }

        if ($expected['feasible']) {
            if (! in_array($output->status, [RunStatus::Optimal, RunStatus::Feasible], true)) {
                $problems[] = "la instancia es factible y respondió {$output->status->value}";
            }

            if ($unscheduled !== [] || $output->hardViolations !== []) {
                $problems[] = 'la instancia es factible y dejó grupos sin programar o violaciones duras';
            }
        } else {
            if (! in_array($output->status, [RunStatus::Partial, RunStatus::Infeasible], true)) {
                $problems[] = "la instancia es infactible y respondió {$output->status->value}";
            }

            if (count($unscheduled) < ($expected['min_unscheduled'] ?? 1)) {
                $problems[] = 'la instancia es infactible y no reportó los grupos sin programar';
            }

            foreach (array_diff($unscheduled, $expected['may_leave_unscheduled']) as $groupId) {
                $problems[] = "{$groupId} sí cabía y quedó sin programar";
            }
        }
    }

    return [array_map(fn (string $problem): string => "{$label}: {$problem}", $problems), $elapsed];
}

/**
 * @return list<string>
 */
function solverFixtureProblemsOfAllEngines(string $fixture): array
{
    $problems = [];

    foreach (app(EngineRegistry::class)->all() as $engine) {
        $problems = [...$problems, ...solverFixtureProblems($engine, $fixture)[0]];
    }

    return $problems;
}

it('todo motor registrado cumple el contrato y lo esperado en el fixture', function (string $fixture) {
    expect(solverFixtureProblemsOfAllEngines($fixture))->toBe([]);
})->with(solverFixtureNames());

it('todo motor registrado cumple el contrato en la instancia real de departamento y varios campus', function () {
    expect(solverFixtureProblemsOfAllEngines('department-multicampus'))->toBe([]);
})->group('solver-slow');

it('cada fixture trae su entrada y lo esperado, y la entrada es válida', function (string $fixture) {
    $expected = solverFixtureJson($fixture, 'expected.json');

    expect(SolverInput::fromArray(solverFixtureJson($fixture, 'input.json'))->groups)->not->toBe([])
        ->and($expected)->toHaveKeys(['feasible', 'notes']);
})->with([...solverFixtureNames(), 'department-multicampus']);

it('la suite señala a un motor que dice revisar reglas duras y no cumple lo esperado', function () {
    $liar = new class implements SolverEngine
    {
        public function key(): string
        {
            return 'liar';
        }

        public function label(): string
        {
            return 'Motor que miente';
        }

        public function supportedVersions(): array
        {
            return [SolverInput::VERSION];
        }

        public function checksHardRules(): bool
        {
            return true;
        }

        public function solve(SolverInput $input, ?Closure $progress = null): SolverOutput
        {
            return (new FixedEngine)->solve($input, $progress);
        }
    };

    expect(solverFixtureProblems($liar, 'small')[0])->not->toBe([])
        ->and(solverFixtureProblems(new FixedEngine, 'small')[0])->toBe([]);
});
