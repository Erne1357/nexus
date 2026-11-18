<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Solver\Actions\CancelSolverRun;
use App\Modules\Solver\Enums\SolverRunStatus;
use App\Modules\Solver\Jobs\RunSolver;
use App\Modules\Solver\Models\SolverRun;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| SV-03: cancelar una corrida (tarea 6.8). En cola pasa directo a cancelada y el job ya no la corre; corriendo, queda
| pedida la cancelación y RunSolver la detiene en el siguiente aviso de avance del motor, salvo que ya no se mueva (su job
| murió): entonces también pasa directo a cancelada. Una que ya no está en curso no se cancela. La Action autoriza con
| SolverRunPolicy::cancel: quien la lanzó o quien puede lanzar ese alcance.
*/

beforeEach(function () {
    $this->by = User::factory()->create();
});

function cancelSolverRun(SolverRun $run, ?User $by = null): void
{
    app(CancelSolverRun::class)->handle($run, $by ?? test()->by);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function cancelableSolverRun(array $attributes = []): SolverRun
{
    return SolverRun::factory()->create(['created_by' => test()->by->id, ...$attributes]);
}

it('SV-03: una corrida en cola se cancela de inmediato, deja bitácora y el job ya no la corre', function () {
    $run = cancelableSolverRun();

    cancelSolverRun($run);
    app()->call([new RunSolver($run->id), 'handle']);

    expect($run->fresh())
        ->status->toBe(SolverRunStatus::Cancelled)
        ->cancel_requested_at->not->toBeNull()
        ->finished_at->not->toBeNull()
        ->started_at->toBeNull()
        ->and(Activity::query()->where('subject_id', $run->id)->sole())
        ->event->toBe('cancelled')
        ->causer_id->toBe($this->by->id)
        ->and(Activity::query()->where('subject_id', $run->id)->sole()->attribute_changes['old'])->toBe(['status' => 'queued']);
});

it('SV-03: una corrida que corre queda con la cancelación pedida, y pedirla otra vez no cambia nada', function () {
    $run = cancelableSolverRun(['status' => SolverRunStatus::Running, 'started_at' => now()]);

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
    $run = cancelableSolverRun(['status' => $status]);

    expect(fn () => cancelSolverRun($run))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['run' => ['Esta corrida ya no está en curso: no se puede cancelar.']]))
        ->and($run->fresh())
        ->status->toBe($status)
        ->cancel_requested_at->toBeNull();
})->with([SolverRunStatus::Done, SolverRunStatus::Failed, SolverRunStatus::Applied, SolverRunStatus::Discarded, SolverRunStatus::Cancelled]);

it('SV-03: revisa el estado de la base y no el del modelo que recibe', function () {
    $run = cancelableSolverRun();
    SolverRun::query()->whereKey($run->id)->update(['status' => SolverRunStatus::Running]);

    cancelSolverRun($run);

    expect($run->fresh())
        ->status->toBe(SolverRunStatus::Running)
        ->cancel_requested_at->not->toBeNull();
});

it('SV-03: una corrida que corre pero ya no se mueve (su job murió) pasa directo a cancelada', function () {
    $run = cancelableSolverRun(['status' => SolverRunStatus::Running, 'started_at' => now()->subMinutes(25), 'updated_at' => now()->subMinutes(21)]);

    cancelSolverRun($run);

    expect($run->fresh())
        ->status->toBe(SolverRunStatus::Cancelled)
        ->cancel_requested_at->not->toBeNull()
        ->finished_at->not->toBeNull()
        ->and(Activity::query()->where('subject_id', $run->id)->sole()->event)->toBe('cancelled');
});

it('SV-03: el jefe de otro departamento no cancela la corrida de esta carga (403); el jefe de la carga, sí', function () {
    $campus = Campus::factory()->create();
    $run = SolverRun::factory()->create();
    $other = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $campus->id, 'department_id' => departmentKey(50)])->create();
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $campus->id, 'department_id' => $run->department_id])->create();

    expect(fn () => cancelSolverRun($run, $other))->toThrow(AuthorizationException::class)
        ->and($run->fresh()->status)->toBe(SolverRunStatus::Queued);

    cancelSolverRun($run, $jefe);

    expect($run->fresh()->status)->toBe(SolverRunStatus::Cancelled);
});
