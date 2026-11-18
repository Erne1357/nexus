<?php

use App\Modules\Solver\Models\SolverRun;
use App\Modules\Solver\Support\RunPayloads;
use Illuminate\Support\Facades\Storage;

/*
| Dónde vive la entrada o la salida de una corrida (tarea 6.8; docs/arquitectura/solver.md, «Ciclo de vida»): en la
| fila si su JSON cabe en 1 MiB y si no, en un archivo `solver/runs/<id>/<entrada o salida>.json` del disco `local`,
| que la cola y la aplicación comparten; la fila guarda la ruta. Se lee igual de los dos lados (jsonb no conserva el orden
| de las llaves).
*/

beforeEach(function () {
    Storage::fake(RunPayloads::DISK);
});

it('deja en la fila lo que cabe y en un archivo lo que pasa de 1 MiB, y lo lee igual', function () {
    $run = SolverRun::factory()->create(['input' => null]);
    $small = ['version' => 1, 'run_id' => $run->id];
    $big = ['version' => 1, 'run_id' => $run->id, 'padding' => str_repeat('a', RunPayloads::ROW_LIMIT_BYTES)];
    $path = "solver/runs/{$run->id}/output.json";

    $inRow = RunPayloads::attributes($run->id, 'input', $small);
    $inFile = RunPayloads::attributes($run->id, 'output', $big);
    $run->forceFill([...$inRow, ...$inFile])->save();

    expect($inRow)->toBe(['input' => $small, 'input_path' => null])
        ->and($inFile)->toBe(['output' => null, 'output_path' => $path])
        ->and(RunPayloads::read($run->fresh(), 'input'))->toEqual($small)
        ->and(RunPayloads::read($run->fresh(), 'output'))->toBe($big);

    Storage::disk(RunPayloads::DISK)->assertExists($path);
});

it('una corrida sin entrada o con el archivo perdido no se lee', function (array $attributes, string $message) {
    $run = SolverRun::factory()->create($attributes);

    expect(fn () => RunPayloads::read($run, 'input'))->toThrow(RuntimeException::class, $message);
})->with([
    'sin entrada' => [['input' => null], 'La corrida no tiene entrada.'],
    'archivo perdido' => [['input' => null, 'input_path' => 'solver/runs/x/input.json'], 'No se encontró el archivo de la entrada de la corrida.'],
]);
