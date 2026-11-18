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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Lanza una corrida del motor sobre la carga de un departamento en una modalidad (en todos sus campus) o sobre los
 * grupos de una carrera (tarea 6.8; docs/dominio/reglas-de-negocio.md, «Corridas del motor»).
 *
 * - SV-04: el motor sale del registro y el límite de tiempo va de MIN_TIME_LIMIT a MAX_TIME_LIMIT segundos (supuesto por
 *   confirmar; el valor por omisión que ofrece la pantalla es el de `solver.time_limits`, SolverSettings). Los pesos de
 *   las reglas blandas no son por corrida: son los que pone BuildSolverInput, los que fija Subdirección para todo el Tec
 *   (`solver.soft_weights`, decisión del usuario del 17 de noviembre de 2026).
 * - SV-01: un departamento tiene a lo más una corrida en cola o corriendo por periodo y modalidad. Una que no se movió
 *   en STALE_AFTER segundos se da por perdida: queda fallida, se avisa a quien la lanzó y se lanza la nueva (supuesto
 *   por confirmar). Si dos entran a la vez, el índice único parcial detiene la segunda con el mismo mensaje. Las de
 *   carrera conviven.
 * - SV-02: arma la entrada con BuildSolverInput y guarda su huella (InputFingerprint) para saber al aceptar si la carga
 *   cambió.
 *
 * Crea la corrida en cola con su entrada (en la fila o en archivo, RunPayloads), su huella, su motor y su autor, deja
 * bitácora («launched») y, después del commit, despacha RunSolver. Con la cola síncrona la corrida ya vuelve terminada.
 *
 * No autoriza: quién lanza lo decide SolverRunPolicy (launchForDepartment, launchForCareer) en el componente que la
 * llama.
 */
final class LaunchSolverRun
{
    public const int MIN_TIME_LIMIT = 10;

    public const int MAX_TIME_LIMIT = 600;

    /** El doble del límite más largo: ninguna corrida sana pasa este tiempo sin moverse. */
    public const int STALE_AFTER = 1200;

    /** El índice único parcial de la migración de solver_runs (6.2). */
    private const string ACTIVE_RUN_INDEX = 'solver_runs_one_active_department_run';

    private const string BUSY_MESSAGE = 'Ya hay una corrida en curso de este departamento.';

    public function __construct(
        private readonly BuildSolverInput $builder,
        private readonly EngineRegistry $engines,
    ) {}

    /**
     * @throws ValidationException si el motor no existe (`engine`), el límite no cabe (`time_limit`) o el alcance no existe
     *                             o ya tiene una corrida en curso (`scope`)
     */
    public function handle(Term $term, Modality $modality, RunScope $scope, string $scopeId, string $engineKey, int $timeLimitSeconds, User $by): SolverRun
    {
        $engine = $this->engine($engineKey);
        $this->ensureTimeLimit($timeLimitSeconds);
        $owner = $this->owner($scope, $scopeId);

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
        } catch (UniqueConstraintViolationException $exception) {
            RunPayloads::discard($payload);

            if (! str_contains($exception->getMessage(), self::ACTIVE_RUN_INDEX)) {
                throw $exception;
            }

            throw ValidationException::withMessages(['scope' => self::BUSY_MESSAGE]);
        }

        dispatch(new RunSolver($run->getKey(), $timeLimitSeconds))->afterCommit();

        return $run->refresh();
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
     * SV-01: las corridas del departamento en curso que no se movieron en STALE_AFTER segundos quedan fallidas, con aviso
     * a quien las lanzó.
     */
    private function releaseStaleRuns(Term $term, Modality $modality, Department $department): void
    {
        $this->inProgress($term, $modality, $department)
            ->where('updated_at', '<', now()->subSeconds(self::STALE_AFTER))
            ->get()
            ->each(function (SolverRun $run): void {
                $run->forceFill([
                    'status' => SolverRunStatus::Failed,
                    'finished_at' => now(),
                    'error' => 'No terminó en '.intdiv(self::STALE_AFTER, 60).' minutos: se lanzó otra corrida.',
                ])->save();

                $run->creator?->notify(new SolverRunFinished($run));
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
