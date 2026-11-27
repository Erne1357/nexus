<?php

use App\Modules\Solver\Contract\RunStatus;
use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Contract\SolverOutput;
use App\Modules\Solver\Engines\FixedEngine;

/*
| El motor de prueba (tarea 6.3): acomoda cada grupo movible en horas seguidas desde el inicio de la jornada de su
| campus, sin revisar reglas, y responde con una salida válida del contrato, honesta (`partial`).
*/

function fixedEngineInput(?callable $change = null): SolverInput
{
    $data = json_decode(file_get_contents(__DIR__.'/../../Fixtures/solver/v1/tiny/input.json'), true);

    return SolverInput::fromArray($change === null ? $data : $change($data));
}

it('responde una salida válida del contrato con el mismo identificador de corrida', function () {
    $input = fixedEngineInput();
    $output = (new FixedEngine)->solve($input);

    expect(SolverOutput::fromArray($output->toArray())->toArray())->toBe($output->toArray())
        ->and($output->runId)->toBe($input->runId)
        ->and($output->status)->toBe(RunStatus::Partial)
        ->and($output->hardViolations)->toBe([])
        ->and($output->metrics->engine)->toBe('fixed');
});

it('pone las horas semanales de cada grupo movible dentro de la jornada de su campus', function () {
    $input = fixedEngineInput();
    $output = (new FixedEngine)->solve($input);
    $grids = collect($input->campuses)->keyBy('code');

    foreach (array_filter($input->groups, fn ($group): bool => $group->movable) as $group) {
        $sessions = array_values(array_filter($output->sessions, fn ($session): bool => $session->groupId === $group->id));
        $grid = $grids[$group->campusCode];

        expect($sessions)->toHaveCount($group->weeklyHours);

        foreach ($sessions as $session) {
            expect($session->startsAt >= $grid->dayStart && $session->endsAt <= $grid->dayEnd)->toBeTrue()
                ->and($grid->days)->toContain($session->day);
        }
    }
});

it('deja sin programar al grupo que no cabe en la jornada y reporta su avance', function () {
    $input = fixedEngineInput(function (array $data): array {
        $data['groups'][0]['weekly_hours'] = 20;

        return $data;
    });
    $progress = [];

    $output = (new FixedEngine)->solve($input, function (int $percent) use (&$progress): void {
        $progress[] = $percent;
    });

    expect(array_map(fn ($unscheduled): string => $unscheduled->groupId, $output->unscheduled))->toBe([$input->groups[0]->id])
        ->and(end($progress))->toBe(100);
});

function fixedEngineHours(int $hours, array $days = [1, 2, 3, 4, 5], int $count = 1): SolverInput
{
    return fixedEngineInput(function (array $data) use ($hours, $days, $count): array {
        $data['campuses'][0]['days'] = $days;
        $data['groups'] = array_slice($data['groups'], 0, 1);

        for ($i = 1; $i < $count; $i++) {
            $data['groups'][$i] = [...$data['groups'][0], 'id' => $data['groups'][0]['id'].$i];
        }

        foreach ($data['groups'] as $i => $group) {
            $data['groups'][$i] = [...$group, 'weekly_hours' => $hours, 'practice_hours' => 0, 'campus_code' => 'C1'];
        }

        return $data;
    });
}

it('pone las horas en sesiones de una hora, en días distintos y a la misma hora: 4 h de lunes a jueves, 5 h de lunes a viernes', function (int $hours, array $days) {
    $output = (new FixedEngine)->solve(fixedEngineHours($hours));

    expect(array_map(fn ($session): int => $session->day, $output->sessions))->toBe($days)
        ->and(array_unique(array_map(fn ($session): string => $session->startsAt, $output->sessions)))->toHaveCount(1)
        ->and(array_unique(array_map(fn ($session): string => $session->endsAt, $output->sessions)))->toBe(['08:00']);
})->with([[4, [1, 2, 3, 4]], [5, [1, 2, 3, 4, 5]]]);

it('6 h usa el sábado solo si la rejilla del campus lo tiene; si no, el grupo queda sin programar con su razón', function () {
    $with = (new FixedEngine)->solve(fixedEngineHours(6, [1, 2, 3, 4, 5, 6]));
    $without = (new FixedEngine)->solve(fixedEngineHours(6));

    expect(array_map(fn ($session): int => $session->day, $with->sessions))->toBe([1, 2, 3, 4, 5, 6])
        ->and($without->sessions)->toBe([])
        ->and($without->unscheduled)->toHaveCount(1)
        ->and($without->unscheduled[0]->reasons[0])->toContain('6 horas', '5 días');
});

it('rota la hora de inicio por grupo para que no caigan todos a las 07:00', function () {
    $output = (new FixedEngine)->solve(fixedEngineHours(2, count: 3));
    $starts = [];

    foreach ($output->sessions as $session) {
        $starts[$session->groupId] = $session->startsAt;
    }

    expect(array_values($starts))->toBe(['07:00', '08:00', '09:00']);
});
