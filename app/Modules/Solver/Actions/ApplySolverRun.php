<?php

namespace App\Modules\Solver\Actions;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Actions\Sessions\ReplaceGroupSessions;
use App\Modules\Scheduling\Actions\SyncScheduleConflicts;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Validation\SessionCandidate;
use App\Modules\Solver\Enums\RunScope;
use App\Modules\Solver\Enums\SolverRunStatus;
use App\Modules\Solver\Models\SolverRun;
use App\Modules\Solver\Queries\BuildSolverInput;
use App\Modules\Solver\Rules\ApplyPlan;
use App\Modules\Solver\Rules\InputFingerprint;
use App\Modules\Solver\Support\RunPayloads;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Acepta una corrida del motor: escribe en `group_sessions` el horario que propuso (SV-05, SV-06; tarea 6.10;
 * docs/dominio/reglas-de-negocio.md, «Corridas del motor»). Autoriza a `$by` con SolverRunPolicy::apply (el jefe de la
 * carga; nunca una corrida de carrera).
 *
 * En una transacción:
 * 1. Relee la corrida con bloqueo: solo una de departamento terminada (`done`).
 * 2. Bloquea la carga (FOR UPDATE: las Actions del editor la toman en compartido, así que esperan) y revisa la vigencia
 *    (SV-02): arma otra vez la entrada con BuildSolverInput y, si su huella no es la de la corrida, no escribe nada.
 * 3. Escribe solo lo diferente de los grupos que se escriben según ApplyPlan (los sin acomodar, los de una violación
 *    dura, los que no recibieron sesiones y los que chocarían con lo que se queda se quedan como están) con
 *    ReplaceGroupSessions, la Action de Scheduling que escribe varios grupos de una vez, con su bitácora por grupo.
 * 4. Deja la corrida aplicada, con quién y cuándo, y su bitácora (`applied`) con el resumen.
 *
 * Después del commit recalcula los choques de la modalidad (SyncScheduleConflicts). Devuelve el resumen: cuántos grupos
 * y sesiones cambió y cuáles se quedaron como estaban, con sus motivos.
 */
final class ApplySolverRun
{
    public const string STALE_MESSAGE = 'La carga cambió desde que se lanzó esta corrida: vuelve a correrla.';

    public function __construct(
        private readonly BuildSolverInput $builder,
        private readonly ReplaceGroupSessions $replace,
        private readonly SyncScheduleConflicts $conflicts,
    ) {}

    /**
     * @return array{groups: int, sessions: int, removed: int, kept: array<string, list<string>>, loans_requested: bool, loans_blocked: bool}
     *
     * @throws ValidationException si la corrida no se puede aceptar o su entrada ya no está vigente (`run`), o si el
     *                             horario no se puede escribir (`session`)
     */
    public function handle(SolverRun $run, User $by): array
    {
        self::ensureAppliable($run);
        Gate::forUser($by)->authorize('apply', $run);

        [$summary, $term, $modality] = DB::transaction(function () use ($run, $by): array {
            $locked = SolverRun::query()->lockForUpdate()->findOrFail($run->getKey());
            self::ensureAppliable($locked);

            $term = Term::query()->findOrFail($locked->term_id);
            $modality = Modality::query()->withTrashed()->findOrFail($locked->modality_id);
            $department = Department::query()->withTrashed()->findOrFail($locked->department_id);

            AcademicLoad::query()->ofDepartment($term->id, $modality->id, $department->id)->lockForUpdate()->first();

            if (! $this->isCurrent($locked, $term, $modality, $department)) {
                throw ValidationException::withMessages(['run' => self::STALE_MESSAGE]);
            }

            $plan = ApplyPlan::of(RunPayloads::input($locked), RunPayloads::output($locked) ?? throw ValidationException::withMessages(['run' => 'La corrida no tiene resultado.']));
            $written = $this->replace->handle(
                array_map(fn (array $sessions): array => array_map(fn (array $slot): SessionCandidate => new SessionCandidate(
                    $slot['day'], $slot['starts_at'], $slot['ends_at'], $slot['room_id'], SessionKind::from($slot['kind']),
                ), $sessions), $plan->writes()),
                $by,
                'desde la corrida del motor del '.$locked->created_at->format('d/m/Y H:i'),
                ['solver_run_id' => $locked->getKey()],
            );

            $summary = [
                'groups' => count($written['groups']),
                'sessions' => array_sum(array_column($written['groups'], 'added')),
                'removed' => array_sum(array_column($written['groups'], 'removed')),
                'kept' => $plan->kept(),
                'loans_requested' => $written['loans_requested'],
                'loans_blocked' => $written['loans_blocked'],
            ];

            $locked->forceFill(['status' => SolverRunStatus::Applied, 'applied_by' => $by->getKey(), 'applied_at' => now()])->save();

            activity()
                ->performedOn($locked)
                ->causedBy($by)
                ->event('applied')
                ->withChanges(['attributes' => ['status' => SolverRunStatus::Applied->value], 'old' => ['status' => SolverRunStatus::Done->value]])
                ->withProperties(['summary' => [...$summary, 'kept' => array_keys($summary['kept'])]])
                ->log("Aceptó la corrida del motor de {$department->name} en {$modality->name}: {$summary['sessions']} sesiones en {$summary['groups']} grupos");

            return [$summary, $term, $modality];
        });

        $this->conflicts->handle($term, $modality);

        return $summary;
    }

    /**
     * SV-02: si la entrada de la corrida sigue vigente (la huella de un armado de hoy es la suya). Lo pregunta también
     * la pantalla antes de abrir «Aceptar».
     */
    public function isCurrent(SolverRun $run, ?Term $term = null, ?Modality $modality = null, ?Department $department = null): bool
    {
        $term ??= Term::query()->findOrFail($run->term_id);
        $modality ??= Modality::query()->withTrashed()->findOrFail($run->modality_id);
        $department ??= Department::query()->withTrashed()->findOrFail($run->department_id);

        return InputFingerprint::of($this->builder->forDepartment($term, $modality, $department, $run->getKey())->input) === $run->input_hash;
    }

    /**
     * @throws ValidationException
     */
    private static function ensureAppliable(SolverRun $run): void
    {
        if ($run->scope_kind !== RunScope::Department) {
            throw ValidationException::withMessages(['run' => 'Una corrida de carrera es para explorar: no se acepta.']);
        }

        if ($run->status !== SolverRunStatus::Done) {
            throw ValidationException::withMessages(['run' => 'Solo se acepta una corrida terminada.']);
        }
    }
}
