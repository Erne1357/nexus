<?php

use App\Modules\Solver\Contract\ContractException;
use App\Modules\Solver\Contract\RoomAgreement;
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

test('HC-10: el préstamo de un solo grupo (`agreements[].group_id`) va y vuelve; sin él, el JSON no lo trae', function () {
    $data = contractFixture('input');
    $groupId = $data['groups'][0]['id'];
    $withGroup = $data;
    $withGroup['agreements'][0]['group_id'] = $groupId;

    expect(SolverInput::fromArray($withGroup)->agreements[0]->groupId)->toBe($groupId)
        ->and(SolverInput::fromArray($withGroup)->toArray())->toBe($withGroup)
        ->and(RoomAgreement::fromArray(RoomAgreement::fromArray($withGroup['agreements'][0])->toArray())->toArray())->toBe($withGroup['agreements'][0])
        ->and(SolverInput::fromArray($data)->agreements[0]->groupId)->toBeNull()
        ->and(SolverInput::fromArray($data)->agreements[0]->toArray())->not->toHaveKey('group_id');
});
