<?php

use App\Modules\Solver\Contract\ContractException;
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

test('la tolerancia de HC-12 ausente vale 0 y presente se conserva', function () {
    $data = contractFixture('input');
    unset($data['weekly_hours_tolerance']);

    expect(SolverInput::fromArray($data)->weeklyHoursTolerance)->toBe(0);

    $data['weekly_hours_tolerance'] = 30;
    $input = SolverInput::fromArray($data);

    expect($input->weeklyHoursTolerance)->toBe(30)
        ->and($input->toArray()['weekly_hours_tolerance'])->toBe(30);
});

test('la tolerancia de HC-12 negativa se rechaza con su ruta', function () {
    $data = contractFixture('input');
    $data['weekly_hours_tolerance'] = -1;

    expect(fn () => SolverInput::fromArray($data))->toThrow(ContractException::class, 'weekly_hours_tolerance');
});

test('hint_sessions ausente vale vacío y no se emite; presente se conserva', function () {
    $data = contractFixture('input');
    expect(SolverInput::fromArray($data)->hintSessions)->toBe([])
        ->and(SolverInput::fromArray($data)->toArray())->not->toHaveKey('hint_sessions');

    $data['hint_sessions'] = [
        ['group_id' => $data['groups'][0]['id'], 'day' => 1, 'starts_at' => '09:00', 'ends_at' => '11:00', 'kind' => 'teoria'],
        ['group_id' => $data['groups'][1]['id'], 'day' => 2, 'starts_at' => '09:00', 'ends_at' => '11:00', 'room_id' => $data['rooms'][0]['id'], 'kind' => 'teoria'],
    ];

    $input = SolverInput::fromArray($data);

    expect($input->hintSessions)->toHaveCount(2)
        ->and($input->toArray()['hint_sessions'])->toBe($data['hint_sessions']);
});
