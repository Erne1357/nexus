<?php

use App\Modules\Scheduling\Rules\Evaluator\HardRuleEvaluator;
use App\Modules\Scheduling\Validation\SessionCandidate;
use App\Modules\Solver\Contract\GroupInput;
use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Evaluation\ContractEvaluator;
use App\Modules\Solver\Evaluation\ContractState;

/*
| El costo del evaluador en memoria (tarea 6.5) sobre la carga real de un departamento en dos campus (fixture
| `department-multicampus`: 1 139 grupos movibles, 2 708 en la entrada). Medido el 17 de noviembre de 2026, sin
| cobertura: la foto ~100 ms, el veredicto completo ~100 ms, withSession ~50 µs y las reglas duras de un grupo ~1 ms
| (docs/arquitectura/solver.md, «Evaluador en memoria»). Los límites dejan holgura para el CI con cobertura.
*/

beforeEach(function () {
    $this->input = SolverInput::fromArray(json_decode(file_get_contents(__DIR__.'/../../Fixtures/solver/v1/department-multicampus/input.json'), true, flags: JSON_THROW_ON_ERROR));
    $this->movable = array_values(array_filter($this->input->groups, fn (GroupInput $group): bool => $group->movable));
});

it('arma la foto de la carga del departamento y da su veredicto completo en menos de 3 segundos cada uno', function () {
    $started = hrtime(true);
    $state = ContractState::from($this->input, ContractState::currentSessions($this->input));
    $built = (hrtime(true) - $started) / 1e9;

    $started = hrtime(true);
    $evaluation = (new ContractEvaluator)->evaluateState($state);
    $evaluated = (hrtime(true) - $started) / 1e9;

    expect(count($this->movable))->toBe(1139)
        ->and($built)->toBeLessThan(3.0)
        ->and($evaluated)->toBeLessThan(3.0)
        ->and($evaluation->hardViolations)->toBe([]);
});

it('pregunta «¿y si…?» por grupo: mil withSession en menos de 1 segundo y cien veredictos de un grupo en menos de 2', function () {
    $state = ContractState::from($this->input, ContractState::currentSessions($this->input))->state;
    $evaluator = new HardRuleEvaluator;
    $candidate = fn (int $i): SessionCandidate => new SessionCandidate(1 + $i % 5, sprintf('%02d:00', 7 + $i % 12), sprintf('%02d:00', 8 + $i % 12));

    $started = hrtime(true);
    for ($i = 0; $i < 1000; $i++) {
        $state->withSession($this->movable[$i]->id, $candidate($i));
    }
    $patched = (hrtime(true) - $started) / 1e9;

    $started = hrtime(true);
    for ($i = 0; $i < 100; $i++) {
        $evaluator->forGroup($state->withSession($this->movable[$i]->id, $candidate($i)), $this->movable[$i]->id);
    }
    $asked = (hrtime(true) - $started) / 1e9;

    expect($patched)->toBeLessThan(1.0)
        ->and($asked)->toBeLessThan(2.0);
});
