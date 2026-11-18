<?php

use App\Modules\Solver\Enums\SolverRunStatus;
use App\Modules\Solver\Models\SolverRun;
use Illuminate\Database\QueryException;

/*
| Lo que `solver_runs` lleva para correr en la cola (tarea 6.8): avance de 0 a 100, mejor puntaje, huella de la entrada,
| cuándo se pidió cancelar y los estados descartada y cancelada. La corrida única de un departamento (SV-01) sigue
| contando solo las que están en cola o corriendo.
*/

it('nace sin avance, sin puntaje, sin huella y sin cancelación pedida', function () {
    $run = SolverRun::factory()->create()->fresh();

    expect($run)
        ->progress->toBe(0)
        ->best_score->toBeNull()
        ->input_hash->toBeNull()
        ->cancel_requested_at->toBeNull();
});

it('el avance va de 0 a 100', function (int $progress) {
    SolverRun::factory()->create(['progress' => $progress]);
})->with([101, -1])->throws(QueryException::class, 'solver_runs_progress_check');

it('guarda el mejor puntaje con decimales y la huella de 64 caracteres', function () {
    $run = SolverRun::factory()->create(['best_score' => 812.5, 'input_hash' => str_repeat('a', 64)])->fresh();

    expect($run->best_score)->toBe(812.5)
        ->and($run->input_hash)->toBe(str_repeat('a', 64));
});

it('SV-01: una corrida descartada o cancelada no ocupa el lugar de la corrida en curso del departamento', function (SolverRunStatus $status) {
    $run = SolverRun::factory()->create(['status' => $status]);

    $next = SolverRun::factory()->create($run->only(['term_id', 'modality_id', 'department_id']));

    expect($run->fresh()->status)->toBe($status)
        ->and($status->isInProgress())->toBeFalse()
        ->and($next->status->isInProgress())->toBeTrue();
})->with([SolverRunStatus::Discarded, SolverRunStatus::Cancelled]);
