<?php

namespace App\Modules\Staffing\Actions\ChangeRequests;

use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Validation\SessionCandidate;
use App\Modules\Solver\Actions\ProposePlacements;
use App\Modules\Solver\Queries\BuildSolverInput;
use App\Modules\Solver\Rules\ApplyPlan;
use App\Modules\Solver\Rules\InputFingerprint;
use App\Modules\Staffing\Enums\ChangeAlternativesStatus;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Queries\GroupsScheduleFingerprintQuery;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * CR-03 (tarea 8.7; supuesto por confirmar): el jefe elige una de las alternativas del motor (CR-02) y con eso aprueba
 * la solicitud por el flujo de ApproveChangeRequest (candados, carga a ajustes, bitácora y avisos que dicen que se
 * aprobó con otro acomodo), en lugar del bloqueo de choques de lo que se pidió. Decide el jefe del departamento
 * (ChangeRequestPolicy::decide, que esta Action vuelve a revisar).
 *
 * La alternativa tiene que ser del cálculo vigente (`ready`; sus ids no se repiten entre cálculos, así que una de una
 * pantalla vieja ya no está). Que siga vigente se revisa en dos tiempos:
 *
 * 1. **Antes de los candados** (lo pesado, una lectura de la foto): la entrada de sus grupos se arma otra vez
 *    (BuildSolverInput::forGroups) y su huella tiene que ser la guardada (SV-02), y sus sesiones no pueden dejar choques
 *    duros nuevos con el validador (AssessChangeImpact::simulateSessions; HC-07, HC-08 y HC-13 no cuentan, no dependen
 *    del acomodo: ApplyPlan::INPUT_RULES).
 * 2. **Con los candados** (lo barato, dos consultas): la huella de todos los grupos reales de la entrada, los que se
 *    mueven y los fijos (los que comparten docente, salón o cohorte), con sus sesiones (GroupsScheduleFingerprintQuery),
 *    tiene que ser la guardada.
 *
 * Si algo cambió, no aprueba: «Esto cambió…» y el jefe las vuelve a pedir. Carrera que queda cubierta: un cambio en
 * cualquiera de esos grupos entre el paso 1 y los candados (por ejemplo, el editor mueve a la hora de la alternativa un
 * grupo fijo del mismo docente) lo ve el paso 2; los del departamento de la carga, además, ya no se mueven hasta aprobar,
 * porque el editor y las Actions de esa carga toman su candado antes de escribir. Lo que no cubre: en ese mismo lapso,
 * la ocupación de los salones por grupos que no entran a la entrada y la disponibilidad de los docentes. Aprobar no
 * escribe sesiones; al aplicar, el editor vuelve a validar con el horario de ese momento, así que eso tampoco deja un
 * choque escrito.
 *
 * La elegida queda en `alternatives.chosen` (id, quién y cuándo), para que el editor de horarios la precargue al
 * aplicar la solicitud. Quien aplica sigue siendo el de siempre según el tipo de la solicitud, aunque la alternativa
 * cambie horas en una de salón (supuesto por confirmar).
 */
final class ChooseChangeAlternative
{
    public const string STALE = 'Esto cambió desde que se calcularon las alternativas: el horario de esos grupos o lo que los rodea ya no es el mismo. Vuelve a pedirlas.';

    public const string NOT_FOUND = 'Esa alternativa ya no está entre las que calculó el motor: vuelve a pedirlas.';

    public function __construct(
        private readonly ApproveChangeRequest $approve,
        private readonly AssessChangeImpact $assess,
        private readonly BuildSolverInput $build,
        private readonly GroupsScheduleFingerprintQuery $groupsFingerprint,
    ) {}

    /**
     * @throws ValidationException si la alternativa no es del cálculo vigente o ya no está vigente, o si la solicitud no
     *                             se puede aprobar
     */
    public function handle(ChangeRequest $request, User $actor, string $alternativeId, ?string $comment = null): ChangeRequest
    {
        Gate::forUser($actor)->authorize('decide', $request);

        $request->refresh();

        if ($request->status !== ChangeRequestStatus::Pending) {
            throw ValidationException::withMessages(['status' => 'La solicitud ya no está pendiente.']);
        }

        if (! $this->isCurrent($request, self::item($request, $alternativeId))) {
            throw ValidationException::withMessages(['alternative' => self::STALE]);
        }

        return $this->approve->approveWithAlternative($request, $actor, $comment, function (ChangeRequest $locked) use ($actor, $alternativeId): void {
            $item = self::item($locked, $alternativeId);

            if ($this->groupsFingerprint->of($item['checked_group_ids']) !== $item['sessions_fingerprint']) {
                throw ValidationException::withMessages(['alternative' => self::STALE]);
            }

            $alternatives = $locked->alternatives;
            $alternatives['chosen'] = ['id' => $alternativeId, 'chosen_by' => $actor->getKey(), 'chosen_at' => now()->toIso8601String()];
            $locked->forceFill(['alternatives' => $alternatives]);

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('alternative_chosen')
                ->withProperties(['alternative' => $alternativeId, 'calculation' => $alternatives['calculation_id'] ?? null, 'kind' => $item['kind'], 'groups' => array_column($item['groups'], 'code')])
                ->log('Eligió una alternativa del motor para la solicitud de cambio');
        });
    }

    /**
     * La alternativa del cálculo vigente.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException si las alternativas no están listas o el id no es de ellas
     */
    private static function item(ChangeRequest $request, string $alternativeId): array
    {
        $alternatives = $request->alternatives;
        $item = ChangeAlternativesStatus::of($alternatives) === ChangeAlternativesStatus::Ready
            ? collect($alternatives['items'] ?? [])->firstWhere('id', $alternativeId)
            : null;

        return $item ?? throw ValidationException::withMessages(['alternative' => self::NOT_FOUND]);
    }

    /**
     * La revisión pesada, sin candados: la foto se lee una vez para el validador y para la entrada del motor.
     *
     * @param  array<string, mixed>  $item
     */
    private function isCurrent(ChangeRequest $request, array $item): bool
    {
        $candidates = [];

        foreach ($item['groups'] as $group) {
            $candidates[$group['group_id']] = array_map(fn (array $session): SessionCandidate => new SessionCandidate($session['day'], $session['starts_at'], $session['ends_at'],
                $session['room_id'], SessionKind::from($session['kind']), $session['replaces_session_id']), $group['sessions']);
        }

        $hard = $this->assess->simulateSessions($request->term_id, $request->modality_id, $candidates)['hard'];

        if (array_filter($hard, fn (array $rule): bool => ! in_array($rule['rule'], ApplyPlan::INPUT_RULES, true)) !== []) {
            return false;
        }

        try {
            $input = $this->build->forGroups(Term::query()->findOrFail($request->term_id), Modality::withTrashed()->findOrFail($request->modality_id),
                $item['movable_group_ids'], [], null, ProposePlacements::TIME_LIMIT_SECONDS, $this->assess->state($request->term_id, $request->modality_id));
        } catch (InvalidArgumentException) {
            return false;
        }

        return InputFingerprint::of($input) === $item['fingerprint'];
    }
}
