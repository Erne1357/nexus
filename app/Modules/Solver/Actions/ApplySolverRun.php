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
use App\Modules\Scheduling\Rules\LoadEditability;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Acepta una corrida del motor: escribe en `group_sessions` el horario que propuso (SV-05, SV-06; tarea 6.10;
 * docs/dominio/reglas-de-negocio.md, «Corridas del motor»). Autoriza a `$by` con SolverRunPolicy::apply (el jefe de la
 * carga; nunca una corrida de carrera).
 *
 * En una transacción, envuelta en LoadEditability::guard() para que un deadlock (40P01) sea un error de validación en
 * `run` y no un 500:
 * 1. Relee la corrida con bloqueo: solo una de departamento terminada (`done`) o que ya se está aplicando (`applying`:
 *    la pasó ahí RequestApplySolverRun y la procesa ApplySolverRunJob). Una segunda aceptación de la misma corrida
 *    espera aquí y luego falla, porque ya no está pendiente.
 * 2. Toma el candado de aceptar de la carga (`pg_advisory_xact_lock` por periodo, modalidad y departamento): dos corridas
 *    de la misma carga se aceptan una después de otra, así la segunda ve la huella que dejó la primera.
 * 3. Toma la carga en compartido (FOR SHARE, como el editor) y exige que exista y se pueda editar
 *    (LoadEditability::EDITABLE): «La carga ya no se puede editar: está aprobada.». FOR SHARE basta para que la carga no
 *    cambie de estado (las transiciones la toman FOR UPDATE) y no detiene al editor mientras se arma la entrada.
 * 4. Revisa la vigencia (SV-02): arma otra vez la entrada con BuildSolverInput y, si su huella no es la de la corrida,
 *    no escribe nada. Lo que el editor guarde entre la huella y la escritura (una ventana de fracciones de segundo) no se
 *    detecta: en los grupos que la corrida escribe gana la corrida, y las exclusiones de la base siguen impidiendo los
 *    empalmes de docente y salón (supuesto por confirmar).
 * 5. Escribe solo lo diferente de los grupos que se escriben según ApplyPlan (los sin acomodar, los de una violación
 *    dura, los que no recibieron sesiones y los que chocarían con lo que se queda se quedan como están) con
 *    ReplaceGroupSessions, que bloquea los grupos FOR UPDATE en orden de id, con su bitácora por grupo.
 * 6. Deja la corrida aplicada, con quién y cuándo, y su bitácora (`applied`) con el resumen.
 *
 * Orden de candados: corrida (FOR UPDATE), candado de aceptar de la carga, carga (FOR SHARE), grupos (FOR UPDATE, por
 * id) y sus sesiones; el mismo orden de carga y grupos que las Actions del editor, y sin tomar la modalidad, así que no
 * hace ciclo con las transiciones de la carga.
 *
 * GroupScheduleChanged implementa ShouldDispatchAfterCommit: sale cuando termina la transacción de afuera, nunca si
 * algo falla después de escribir. Después del commit recalcula los choques de la modalidad (SyncScheduleConflicts); si
 * eso falla, la corrida ya quedó aplicada: se registra en el log y el resumen lo dice (`conflicts_pending`; los choques
 * se vuelven a calcular con el siguiente cambio de la carga o desde el tablero de choques). Devuelve el resumen:
 * cuántos grupos y sesiones cambió y cuáles se quedaron como estaban, con sus motivos.
 *
 * Una corrida `done` se autoriza aquí; una `applying` ya la autorizó RequestApplySolverRun al pasarla a ese estado, y el
 * job no vuelve a autorizar (la policy `apply` pide `done`).
 */
final class ApplySolverRun
{
    public const string STALE_MESSAGE = 'La carga cambió desde que se lanzó esta corrida: vuelve a correrla.';

    public function __construct(
        private readonly BuildSolverInput $builder,
        private readonly ReplaceGroupSessions $replace,
        private readonly SyncScheduleConflicts $conflicts,
        private readonly LoadEditability $editability,
    ) {}

    /**
     * @return array{groups: int, sessions: int, removed: int, kept: array<string, list<string>>, loans_requested: bool, loans_blocked: bool, conflicts_pending: bool}
     *
     * @throws ValidationException si la corrida no se puede aceptar o su entrada ya no está vigente (`run`), o si el
     *                             horario no se puede escribir (`session`)
     */
    public function handle(SolverRun $run, User $by): array
    {
        self::ensureAppliable($run, SolverRunStatus::Done, SolverRunStatus::Applying);

        if ($run->status === SolverRunStatus::Done) {
            Gate::forUser($by)->authorize('apply', $run);
        }

        [$summary, $term, $modality] = $this->editability->guard(fn (): array => DB::transaction(function () use ($run, $by): array {
            $locked = SolverRun::query()->lockForUpdate()->findOrFail($run->getKey());
            self::ensureAppliable($locked, SolverRunStatus::Done, SolverRunStatus::Applying);

            $term = Term::query()->findOrFail($locked->term_id);
            $modality = Modality::query()->withTrashed()->findOrFail($locked->modality_id);
            $department = Department::query()->withTrashed()->findOrFail($locked->department_id);

            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["solver_apply:{$term->id}:{$modality->id}:{$department->id}"]);
            $this->ensureLoadEditable(AcademicLoad::query()->ofDepartment($term->id, $modality->id, $department->id)->sharedLock()->first(['id', 'status']));

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
                'conflicts_pending' => false,
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
        }), 'run');

        try {
            $this->conflicts->handle($term, $modality);
        } catch (Throwable $exception) {
            Log::error('No se recalcularon los choques después de aceptar una corrida del motor.', ['solver_run_id' => $run->getKey(), 'exception' => $exception]);
            $summary['conflicts_pending'] = true;
        }

        return $summary;
    }

    /**
     * La carga existe y está en un estado en que se editan horarios (LoadEditability::EDITABLE).
     *
     * @throws ValidationException
     */
    private function ensureLoadEditable(?AcademicLoad $load): void
    {
        if ($load === null) {
            throw ValidationException::withMessages(['run' => 'La carga ya no se puede editar: no existe en este periodo y modalidad.']);
        }

        if (! in_array($load->status, LoadEditability::EDITABLE, true)) {
            throw ValidationException::withMessages(['run' => 'La carga ya no se puede editar: está '.mb_strtolower($load->status->label()).'.']);
        }
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
    public static function ensureAppliable(SolverRun $run, SolverRunStatus ...$allowed): void
    {
        if ($run->scope_kind !== RunScope::Department) {
            throw ValidationException::withMessages(['run' => 'Una corrida de carrera es para explorar: no se acepta.']);
        }

        if (! in_array($run->status, $allowed, true)) {
            throw ValidationException::withMessages(['run' => 'Solo se acepta una corrida terminada.']);
        }
    }
}
