<?php

namespace App\Modules\Staffing\Actions\ChangeRequests;

use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Validation\SessionCandidate;
use App\Modules\Solver\Actions\ProposePlacements;
use App\Modules\Solver\Queries\BuildSolverInput;
use App\Modules\Solver\Rules\InputFingerprint;
use App\Modules\Staffing\Models\ChangeRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * CR-03 (tarea 8.7; supuesto por confirmar): el jefe elige una de las alternativas del motor (CR-02) y con eso aprueba
 * la solicitud por el flujo de ApproveChangeRequest (candados, carga a ajustes, bitácora y avisos), en lugar del
 * bloqueo de choques de la propuesta original. Decide el jefe del departamento (ChangeRequestPolicy::decide, que esta
 * Action vuelve a revisar).
 *
 * Con los candados tomados revisa que la alternativa siga vigente: la entrada de sus grupos se arma otra vez
 * (BuildSolverInput::forGroups) y su huella tiene que ser la guardada (SV-02), y sus sesiones no pueden dejar choques
 * duros con el validador (AssessChangeImpact::simulateSessions). Si algo cambió, no aprueba: «Esto cambió…» y el jefe
 * vuelve a pedirlas. La elegida queda en `alternatives.chosen` (id, quién y cuándo), para que el editor de horarios la
 * precargue al aplicar la solicitud; no escribe sesiones.
 */
final class ChooseChangeAlternative
{
    public const string STALE = 'Esto cambió desde que se calcularon las alternativas: el horario de esos grupos o lo que los rodea ya no es el mismo. Vuelve a pedirlas.';

    public function __construct(
        private readonly ApproveChangeRequest $approve,
        private readonly AssessChangeImpact $assess,
        private readonly BuildSolverInput $build,
    ) {}

    /**
     * @throws ValidationException si la alternativa no existe o ya no está vigente, o si la solicitud no se puede aprobar
     */
    public function handle(ChangeRequest $request, User $actor, string $alternativeId, ?string $comment = null): ChangeRequest
    {
        Gate::forUser($actor)->authorize('decide', $request);

        return $this->approve->approve($request, $actor, $comment, function (ChangeRequest $locked) use ($actor, $alternativeId): void {
            $alternatives = $locked->alternatives ?? [];
            $item = collect($alternatives['items'] ?? [])->firstWhere('id', $alternativeId);

            if ($item === null) {
                throw ValidationException::withMessages(['alternative' => 'Esa alternativa no está entre las que calculó el motor: vuelve a pedirlas.']);
            }

            if (! $this->isCurrent($locked, $item)) {
                throw ValidationException::withMessages(['alternative' => self::STALE]);
            }

            $alternatives['chosen'] = ['id' => $alternativeId, 'chosen_by' => $actor->getKey(), 'chosen_at' => now()->toIso8601String()];
            $locked->forceFill(['alternatives' => $alternatives]);

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('alternative_chosen')
                ->withProperties(['alternative' => $alternativeId, 'kind' => $item['kind'], 'groups' => array_column($item['groups'], 'code')])
                ->log('Eligió una alternativa del motor para la solicitud de cambio');
        });
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function isCurrent(ChangeRequest $request, array $item): bool
    {
        try {
            $input = $this->build->forGroups(Term::query()->findOrFail($request->term_id), Modality::withTrashed()->findOrFail($request->modality_id),
                $item['movable_group_ids'], [], null, ProposePlacements::TIME_LIMIT_SECONDS);
        } catch (InvalidArgumentException) {
            return false;
        }

        if (InputFingerprint::of($input) !== $item['fingerprint']) {
            return false;
        }

        $candidates = [];

        foreach ($item['groups'] as $group) {
            $candidates[$group['group_id']] = array_map(fn (array $session): SessionCandidate => new SessionCandidate($session['day'], $session['starts_at'], $session['ends_at'],
                $session['room_id'], SessionKind::from($session['kind']), $session['replaces_session_id']), $group['sessions']);
        }

        return $this->assess->simulateSessions($request->term_id, $request->modality_id, $candidates)['hard'] === [];
    }
}
