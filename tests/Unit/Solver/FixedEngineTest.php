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
