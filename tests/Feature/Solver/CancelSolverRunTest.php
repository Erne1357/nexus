<?php

use App\Modules\Core\Models\User;
use App\Modules\Solver\Actions\CancelSolverRun;
use App\Modules\Solver\Enums\SolverRunStatus;
use App\Modules\Solver\Jobs\RunSolver;
use App\Modules\Solver\Models\SolverRun;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| SV-03: cancelar una corrida (tarea 6.8). En cola pasa directo a cancelada y el job ya no la corre; corriendo, queda
| pedida la cancelación y RunSolver la detiene en el siguiente aviso de avance del motor. Una que ya no está en curso no
| se cancela. La Action no autoriza: lo hace el componente con SolverRunPolicy.
*/

beforeEach(function () {
    $this->by = User::factory()->create();
});

function cancelSolverRun(SolverRun $run): void
{
    app(CancelSolverRun::class)->handle($run, test()->by);
}

it('SV-03: una corrida en cola se cancela de inmediato, deja bitácora y el job ya no la corre', function () {
    $run = SolverRun::factory()->create();

    cancelSolverRun($run);
    app()->call([new RunSolver($run->id), 'handle']);

    expect($run->fresh())
        ->status->toBe(SolverRunStatus::Cancelled)
        ->cancel_requested_at->not->toBeNull()
        ->finished_at->not->toBeNull()
        ->started_at->toBeNull()
        ->and(Activity::query()->where('subject_id', $run->id)->sole())
        ->event->toBe('cancelled')
        ->causer_id->toBe($this->by->id);
});

it('SV-03: una corrida que corre queda con la cancelación pedida, y pedirla otra vez no cambia nada', function () {
    $run = SolverRun::factory()->create(['status' => SolverRunStatus::Running, 'started_at' => now()]);

    cancelSolverRun($run);
    $requestedAt = $run->fresh()->cancel_requested_at;
    $this->travel(5)->seconds();
    cancelSolverRun($run->fresh());

    expect($run->fresh())
        ->status->toBe(SolverRunStatus::Running)
        ->cancel_requested_at->toEqual($requestedAt)
        ->finished_at->toBeNull()
        ->and(Activity::query()->where('subject_id', $run->id)->sole())
        ->event->toBe('cancel_requested');
});

it('SV-03: una corrida que ya no está en curso no se cancela', function (SolverRunStatus $status) {
    $run = SolverRun::factory()->create(['status' => $status]);

    expect(fn () => cancelSolverRun($run))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['run' => ['Esta corrida ya no está en curso: no se puede cancelar.']]))
        ->and($run->fresh())
        ->status->toBe($status)
        ->cancel_requested_at->toBeNull();
})->with([SolverRunStatus::Done, SolverRunStatus::Failed, SolverRunStatus::Applied, SolverRunStatus::Discarded, SolverRunStatus::Cancelled]);

it('SV-03: revisa el estado de la base y no el del modelo que recibe', function () {
    $run = SolverRun::factory()->create();
    SolverRun::query()->whereKey($run->id)->update(['status' => SolverRunStatus::Running]);

    cancelSolverRun($run);

    expect($run->fresh())
        ->status->toBe(SolverRunStatus::Running)
        ->cancel_requested_at->not->toBeNull();
});
