<?php

use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Contract\SolverOutput;
use App\Modules\Solver\Evaluation\ContractEvaluator;

/*
| El puntaje blando del servicio CP-SAT es el de PHP (tarea 7.6; docs/arquitectura/solver.md, «Modelo CP-SAT:
| objetivo»). El servicio calcula el `soft_score` de su salida con su espejo en Python de SoftScorer (`score.py`); aquí
| el evaluador de PHP vuelve a calcularlo sobre las mismas sesiones y tiene que dar el mismo total y el mismo desglose.
| Las salidas son las que guardó el servicio para `small`, `medium` y la carga real `department-multicampus`
| (`cpsat-output.json`, generadas con `services/solver/scripts/objective.py`) y una solución a mano que penaliza en
| las nueve reglas (`scores/all-rules`, la de `services/solver/tests/test_model_score.py`). pytest revisa del otro
| lado que cada `soft_score` guardado es el de `score.py`.
*/

/**
 * Las parejas de entrada y salida guardadas, por nombre.
 *
 * @return array<string, array{string, string}>
 */
function cpSatScoreCases(): array
{
    $root = __DIR__.'/../../Fixtures/solver';
    $cases = [];

    foreach (glob($root.'/v1/*/cpsat-output.json') as $output) {
        $cases[basename(dirname($output))] = [dirname($output).'/input.json', $output];
    }

    foreach (glob($root.'/scores/*', GLOB_ONLYDIR) as $folder) {
        $cases[basename($folder)] = [$folder.'/input.json', $folder.'/output.json'];
    }

    return $cases;
}

/**
 * @return array<string, mixed>
 */
function cpSatScoreJson(string $path): array
{
    return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

it('el puntaje blando de la salida guardada es el del evaluador de PHP', function (string $input, string $output) {
    $data = SolverInput::fromArray(cpSatScoreJson($input));
    $saved = SolverOutput::fromArray(cpSatScoreJson($output));

    $evaluation = (new ContractEvaluator)->evaluate($data, $saved->sessions);

    expect($evaluation->softScore->toArray())->toBe($saved->softScore->toArray())
        ->and($saved->softScore->total)->toBeGreaterThan(0);
})->with(cpSatScoreCases());

it('las salidas de CP-SAT guardadas no tienen violaciones duras para el evaluador', function (string $input, string $output) {
    $data = SolverInput::fromArray(cpSatScoreJson($input));
    $saved = SolverOutput::fromArray(cpSatScoreJson($output));

    expect((new ContractEvaluator)->evaluate($data, $saved->sessions)->hardViolations)->toBe([]);
})->with(array_filter(cpSatScoreCases(), fn (array $case): bool => str_ends_with($case[1], 'cpsat-output.json')));

it('entre las salidas guardadas está la carga real de un departamento', function () {
    expect(array_keys(cpSatScoreCases()))->toContain('small', 'medium', 'department-multicampus', 'all-rules');
});

it('la solución a mano penaliza en las nueve reglas', function () {
    [$input, $output] = cpSatScoreCases()['all-rules'];
    $data = SolverInput::fromArray(cpSatScoreJson($input));
    $saved = SolverOutput::fromArray(cpSatScoreJson($output));

    $byRule = (new ContractEvaluator)->evaluate($data, $saved->sessions)->softScore->byRule;

    expect($byRule)->toBe(['SC-01' => 20, 'SC-02' => 10, 'SC-03' => 30, 'SC-04' => 40, 'SC-05' => 20, 'SC-06' => 10, 'SC-07' => 10, 'SC-08' => 40, 'SC-09' => 10]);
});
