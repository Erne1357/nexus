<?php

namespace App\Modules\Solver\Actions;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Solver\Engines\EngineRegistry;
use App\Modules\Solver\Engines\SolverEngine;
use App\Modules\Solver\Enums\RunScope;
use App\Modules\Solver\Enums\SolverRunStatus;
use App\Modules\Solver\Jobs\RunSolver;
use App\Modules\Solver\Models\SolverRun;
use App\Modules\Solver\Notifications\SolverRunFinished;
use App\Modules\Solver\Queries\BuildSolverInput;
use App\Modules\Solver\Rules\InputFingerprint;
use App\Modules\Solver\Support\RunPayloads;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Lanza una corrida del motor sobre la carga de un departamento en una modalidad (en todos sus campus) o sobre los
 * grupos de una carrera (tarea 6.8; docs/dominio/reglas-de-negocio.md, «Corridas del motor»).
 *
 * - SV-04: el motor sale del registro y el límite de tiempo va de MIN_TIME_LIMIT a MAX_TIME_LIMIT segundos (supuesto por
 *   confirmar; el valor por omisión que ofrece la pantalla es el de `solver.time_limits`, SolverSettings). Los pesos de
 *   las reglas blandas no son por corrida: son los que pone BuildSolverInput, los que fija Subdirección para todo el Tec
 *   (`solver.soft_weights`, decisión del usuario del 17 de noviembre de 2026).
 * - SV-01: un departamento tiene a lo más una corrida en cola o corriendo por periodo y modalidad. Una que no se movió
 *   en RunSolver::STALE_AFTER segundos se da por perdida: queda fallida (solo si sigue en curso y sin moverse), se avisa
 *   a quien la lanzó y se lanza la nueva (supuesto por confirmar). Si dos entran a la vez, el índice único parcial
 *   detiene la segunda con el mismo mensaje. Las de carrera conviven.
 * - SV-02: arma la entrada con BuildSolverInput y guarda su huella (InputFingerprint) para saber al aceptar si la carga
 *   cambió.
 *
 * Crea la corrida en cola con su entrada (en la fila o en archivo, RunPayloads), su huella, su motor y su autor, deja
 * bitácora («launched») y, después del commit, despacha RunSolver. Con la cola síncrona la corrida ya vuelve terminada.
 * Si algo falla al guardar, el archivo de la entrada se borra. Si no se puede encolar, la corrida que sigue en cola queda
 * fallida («No se pudo encolar la corrida.»), lo que libera al departamento, y el error sale.
 *
 * Autoriza a `$by` con SolverRunPolicy (launchForDepartment o launchForCareer) después de encontrar el alcance: una
 * AuthorizationException (403) si no puede lanzar ese alcance. El componente puede preguntar lo mismo antes para
 * mostrar u ocultar el botón.
 */
final class LaunchSolverRun
{
    public const int MIN_TIME_LIMIT = 10;

    public const int MAX_TIME_LIMIT = 600;

    /** El índice único parcial de la migración de solver_runs (6.2). */
    private const string ACTIVE_RUN_INDEX = 'solver_runs_one_active_department_run';

    private const string BUSY_MESSAGE = 'Ya hay una corrida en curso de este departamento.';

    public function __construct(
        private readonly BuildSolverInput $builder,
        private readonly EngineRegistry $engines,
    ) {}

    /**
     * @throws ValidationException si el alcance no existe o ya tiene una corrida en curso (`scope`), el motor no existe
     *                             (`engine`) o el límite no cabe (`time_limit`)
     * @throws AuthorizationException si `$by` no puede lanzar ese alcance
     */
    public function handle(Term $term, Modality $modality, RunScope $scope, string $scopeId, string $engineKey, int $timeLimitSeconds, User $by): SolverRun
    {
        $owner = $this->owner($scope, $scopeId);
        Gate::forUser($by)->authorize($owner instanceof Department ? 'launchForDepartment' : 'launchForCareer', [SolverRun::class, $modality->getKey(), $owner->getKey()]);
        $engine = $this->engine($engineKey);
        $this->ensureTimeLimit($timeLimitSeconds);

        if ($owner instanceof Department) {
            $this->releaseStaleRuns($term, $modality, $owner);
            $this->ensureNoRunInProgress($term, $modality, $owner);
        }

        $runId = (string) Str::uuid7();
        $build = $owner instanceof Department
            ? $this->builder->forDepartment($term, $modality, $owner, $runId)
            : $this->builder->forCareer($term, $modality, $owner, $runId);
        $payload = RunPayloads::attributes($runId, 'input', array_replace($build->input->toArray(), ['time_limit_seconds' => $timeLimitSeconds]));

        try {
            $run = DB::transaction(function () use ($term, $modality, $scope, $owner, $engine, $timeLimitSeconds, $by, $runId, $build, $payload): SolverRun {
                $run = SolverRun::query()->forceCreate([
                    'id' => $runId,
                    'term_id' => $term->getKey(),
                    'modality_id' => $modality->getKey(),
                    'scope_kind' => $scope,
                    'department_id' => $owner instanceof Department ? $owner->getKey() : null,
                    'career_id' => $owner instanceof Career ? $owner->getKey() : null,
                    'engine' => $engine->key(),
                    'status' => SolverRunStatus::Queued,
                    'input_hash' => InputFingerprint::of($build->input),
                    'progress' => 0,
                    'created_by' => $by->getKey(),
                    ...$payload,
                ]);

                activity()
                    ->performedOn($run)
                    ->causedBy($by)
                    ->event('launched')
                    ->withChanges(['attributes' => [
                        'scope_kind' => $scope->value,
                        'department_id' => $run->department_id,
                        'career_id' => $run->career_id,
                        'engine' => $run->engine,
                        'time_limit_seconds' => $timeLimitSeconds,
                        'input_hash' => $run->input_hash,
                    ]])
                    ->log("Lanzó «{$engine->label()}» sobre ".($owner instanceof Department ? 'la carga de ' : 'los grupos de ')."{$owner->name} en {$modality->name}, {$term->name}");

                return $run;
            });
        } catch (Throwable $exception) {
            RunPayloads::discard($payload);

            if ($exception instanceof UniqueConstraintViolationException && str_contains($exception->getMessage(), self::ACTIVE_RUN_INDEX)) {
                throw ValidationException::withMessages(['scope' => self::BUSY_MESSAGE]);
            }

            throw $exception;
        }

        $this->enqueue($run, $timeLimitSeconds);

        return $run->refresh();
    }

    /**
     * Despacha RunSolver después del commit (con la cola síncrona, la corre aquí). Si el despacho lanza, la corrida que
     * siga en cola queda fallida y el error sale; una que el job ya dejó fallida (cola síncrona) no se toca.
     */
    private function enqueue(SolverRun $run, int $timeLimitSeconds): void
    {
        try {
            Bus::dispatch(new RunSolver($run->getKey(), $timeLimitSeconds));
        } catch (Throwable $exception) {
            SolverRun::query()
                ->whereKey($run->getKey())
                ->where('status', SolverRunStatus::Queued)
                ->update(['status' => SolverRunStatus::Failed, 'finished_at' => now(), 'error' => 'No se pudo encolar la corrida.']);

            throw $exception;
        }
    }

    /**
     * @throws ValidationException
     */
    private function engine(string $key): SolverEngine
    {
        try {
            return $this->engines->get($key);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['engine' => $exception->getMessage()]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function ensureTimeLimit(int $seconds): void
    {
        if ($seconds < self::MIN_TIME_LIMIT || $seconds > self::MAX_TIME_LIMIT) {
            throw ValidationException::withMessages(['time_limit' => 'El tiempo máximo de una corrida va de '.self::MIN_TIME_LIMIT.' a '.self::MAX_TIME_LIMIT.' segundos.']);
        }
    }

    /**
     * @throws ValidationException
     */
    private function owner(RunScope $scope, string $scopeId): Department|Career
    {
        return $scope === RunScope::Department
            ? KeyLookup::find(Department::query(), $scopeId) ?? throw ValidationException::withMessages(['scope' => 'No existe ese departamento.'])
            : KeyLookup::find(Career::query(), $scopeId) ?? throw ValidationException::withMessages(['scope' => 'No existe esa carrera.']);
    }

    /**
     * SV-01: las corridas del departamento en curso que no se movieron en RunSolver::STALE_AFTER segundos quedan
     * fallidas, con aviso a quien las lanzó. Cada una se actualiza solo si sigue en curso y sin moverse, así que una que
     * terminó en medio no se pisa ni avisa.
     */
    private function releaseStaleRuns(Term $term, Modality $modality, Department $department): void
    {
        $limit = now()->subSeconds(RunSolver::STALE_AFTER);

        $this->inProgress($term, $modality, $department)
            ->where('updated_at', '<', $limit)
            ->pluck('id')
            ->each(function (string $runId) use ($limit): void {
                $changed = SolverRun::query()
                    ->whereKey($runId)
                    ->whereIn('status', [SolverRunStatus::Queued, SolverRunStatus::Running])
                    ->where('updated_at', '<', $limit)
                    ->update([
                        'status' => SolverRunStatus::Failed,
                        'finished_at' => now(),
                        'error' => 'No terminó en '.intdiv(RunSolver::STALE_AFTER, 60).' minutos: se lanzó otra corrida.',
                    ]);

                if ($changed === 1) {
                    $run = SolverRun::query()->findOrFail($runId);
                    $run->creator?->notify(new SolverRunFinished($run));
                }
            });
    }

    /**
     * SV-01: antes de armar la entrada, que el departamento no tenga una corrida en curso.
     *
     * @throws ValidationException
     */
    private function ensureNoRunInProgress(Term $term, Modality $modality, Department $department): void
    {
        if ($this->inProgress($term, $modality, $department)->exists()) {
            throw ValidationException::withMessages(['scope' => self::BUSY_MESSAGE]);
        }
    }

    /**
     * @return Builder<SolverRun>
     */
    private function inProgress(Term $term, Modality $modality, Department $department): Builder
    {
        return SolverRun::query()
            ->where('term_id', $term->getKey())
            ->where('modality_id', $modality->getKey())
            ->where('scope_kind', RunScope::Department)
            ->where('department_id', $department->getKey())
            ->whereIn('status', [SolverRunStatus::Queued, SolverRunStatus::Running]);
    }
}
