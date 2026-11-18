<?php

use App\Modules\Solver\Contract\RunStatus;
use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Contract\SolverOutput;

/**
 * @return array<string, mixed>
 */
function contractFixture(string $name): array
{
    return json_decode(file_get_contents(__DIR__.'/../../Fixtures/solver/v1/tiny/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR);
}

test('la entrada tiny se lee y vuelve a su JSON exacto', function () {
    $data = contractFixture('input');

    $input = SolverInput::fromArray($data);

    expect($input->toArray())->toBe($data)
        ->and($input->groups)->toHaveCount(3)
        ->and($input->campuses)->toHaveCount(2);
});

test('la salida tiny se lee y vuelve a su JSON exacto', function () {
    $data = contractFixture('output');

    $output = SolverOutput::fromArray($data);

    expect($output->toArray())->toBe($data)
        ->and($output->status)->toBe(RunStatus::Feasible)
        ->and($output->sessions)->toHaveCount(9);
});
