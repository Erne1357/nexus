<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Solver\Actions\DiscardSolverRun;
use App\Modules\Solver\Enums\SolverRunStatus;
use App\Modules\Solver\Models\SolverRun;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| SV-07: descartar una corrida terminada (tarea 6.10). Queda descartada, ya no se puede aceptar y no toca ninguna
| sesión. La descarta quien la lanzó o quien puede lanzar ese alcance (SolverRunPolicy::discard): el jefe, las de su
| carga; el coordinador, las de su carrera.
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create();
    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => departmentKey(10)])->create();
    $this->coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => careerKey(20)])->create();
    $this->departmentRun = SolverRun::factory()->done()->create(['department_id' => departmentKey(10)]);
    $this->careerRun = SolverRun::factory()->forCareer()->done()->create(['career_id' => careerKey(20), 'created_by' => $this->coordinador->id]);
});

function discardSolverRun(SolverRun $run, User $by): void
{
    app(DiscardSolverRun::class)->handle($run, $by);
}

it('SV-07: el jefe descarta una corrida terminada de su carga, con bitácora y sin tocar ninguna sesión', function () {
    $sessions = GroupSession::query()->count();

    discardSolverRun($this->departmentRun, $this->jefe);

    expect($this->departmentRun->fresh()->status)->toBe(SolverRunStatus::Discarded)
        ->and(GroupSession::query()->count())->toBe($sessions)
        ->and(Activity::query()->where('subject_id', $this->departmentRun->id)->sole())
        ->event->toBe('discarded')
        ->causer_id->toBe($this->jefe->id)
        ->and($this->jefe->can('apply', $this->departmentRun->fresh()))->toBeFalse();
});

it('SV-07: el coordinador descarta las de su carrera, no las de departamento ni las de otra carrera', function () {
    $other = SolverRun::factory()->forCareer()->done()->create(['career_id' => careerKey(21)]);

    discardSolverRun($this->careerRun, $this->coordinador);

    expect($this->careerRun->fresh()->status)->toBe(SolverRunStatus::Discarded)
        ->and(fn () => discardSolverRun($this->departmentRun, $this->coordinador))->toThrow(AuthorizationException::class)
        ->and(fn () => discardSolverRun($other, $this->coordinador))->toThrow(AuthorizationException::class)
        ->and($this->departmentRun->fresh()->status)->toBe(SolverRunStatus::Done);
});

it('SV-07: solo se descarta una corrida terminada', function (SolverRunStatus $status) {
    $this->departmentRun->update(['status' => $status]);

    expect(fn () => discardSolverRun($this->departmentRun->fresh(), $this->jefe))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['run' => ['Solo se descarta una corrida terminada.']]))
        ->and($this->departmentRun->fresh()->status)->toBe($status);
})->with([SolverRunStatus::Queued, SolverRunStatus::Running, SolverRunStatus::Failed, SolverRunStatus::Applied, SolverRunStatus::Discarded, SolverRunStatus::Cancelled]);
