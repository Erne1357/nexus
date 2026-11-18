<?php

use App\Modules\Solver\Enums\SolverRunStatus;
use App\Modules\Solver\Models\SolverRun;
use App\Modules\Solver\Support\RunPayloads;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
| `solver:prune-files` (tarea 6.8, revisión del Riel A en #587): borra los archivos de entrada y salida de las corridas
| fallidas, canceladas y descartadas que terminaron hace más de `--days` días (30 por omisión) y quita su ruta de la fila;
| también las carpetas de `solver/runs` sin corrida (una que no se llegó a guardar) de más de un día. Nunca toca las
| corridas aplicadas, las terminadas ni las que están en curso. Se corre a mano.
*/

beforeEach(function () {
    Storage::fake(RunPayloads::DISK);
});

/**
 * Una corrida con su entrada y su salida en archivos.
 */
function prunableSolverRun(SolverRunStatus $status, int $daysAgo): SolverRun
{
    $run = SolverRun::factory()->create(['status' => $status, 'input' => null, 'finished_at' => now()->subDays($daysAgo)]);
    $disk = Storage::disk(RunPayloads::DISK);
    $disk->put("solver/runs/{$run->id}/input.json", '{}');
    $disk->put("solver/runs/{$run->id}/output.json", '{}');
    $run->forceFill(['input_path' => "solver/runs/{$run->id}/input.json", 'output_path' => "solver/runs/{$run->id}/output.json"])->save();

    return $run;
}

function orphanSolverRunFolder(int $daysAgo): string
{
    $folder = 'solver/runs/'.Str::uuid7();
    $disk = Storage::disk(RunPayloads::DISK);
    $disk->put("{$folder}/input.json", '{}');
    touch($disk->path("{$folder}/input.json"), now()->subDays($daysAgo)->getTimestamp());

    return $folder;
}

it('borra los archivos de las corridas que ya no se van a aplicar y deja los demás', function () {
    $pruned = [
        prunableSolverRun(SolverRunStatus::Failed, 31),
        prunableSolverRun(SolverRunStatus::Cancelled, 40),
        prunableSolverRun(SolverRunStatus::Discarded, 31),
    ];
    $kept = [
        prunableSolverRun(SolverRunStatus::Failed, 10),
        prunableSolverRun(SolverRunStatus::Applied, 90),
        prunableSolverRun(SolverRunStatus::Done, 90),
    ];
    $oldOrphan = orphanSolverRunFolder(2);
    $newOrphan = orphanSolverRunFolder(0);

    $this->artisan('solver:prune-files')
        ->expectsOutput('Se borraron los archivos de 3 corridas y 1 carpeta sin corrida.')
        ->assertSuccessful();

    $disk = Storage::disk(RunPayloads::DISK);

    foreach ($pruned as $run) {
        expect($run->fresh())->input_path->toBeNull()->output_path->toBeNull()
            ->and($disk->exists("solver/runs/{$run->id}"))->toBeFalse();
    }

    foreach ($kept as $run) {
        expect($run->fresh()->input_path)->not->toBeNull()
            ->and($disk->exists("solver/runs/{$run->id}/input.json"))->toBeTrue();
    }

    expect($disk->exists($oldOrphan))->toBeFalse()
        ->and($disk->exists("{$newOrphan}/input.json"))->toBeTrue();
});

it('con --days cuenta desde esos días', function () {
    $run = prunableSolverRun(SolverRunStatus::Cancelled, 10);

    $this->artisan('solver:prune-files', ['--days' => 7])->assertSuccessful();

    expect($run->fresh()->input_path)->toBeNull();
});
