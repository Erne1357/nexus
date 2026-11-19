<?php

namespace App\Modules\Staffing\Actions\ChangeRequests;

use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Solver\Actions\ProposePlacements;
use App\Modules\Solver\Queries\BuildSolverInput;
use App\Modules\Solver\Rules\InputFingerprint;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Support\ChangeAlternatives;
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
 * precargue al aplicar la solicitud; no escribe sesiones (las escribe ApplyChosenAlternative, CR-04).
 *
 * Después del commit, si la cadena mueve un grupo de otra carrera, avisa a su coordinación qué grupo se mueve, de dónde
 * a dónde y por qué solicitud (NotifyChangeRequest::careerGroupsMoved; decidido por el usuario el 18 de noviembre de
 * 2026: el jefe decide sin su visto bueno).
 */
final class ChooseChangeAlternative
{
    public const string STALE = 'Esto cambió desde que se calcularon las alternativas: el horario de esos grupos o lo que los rodea ya no es el mismo. Vuelve a pedirlas.';

    public function __construct(
        private readonly ApproveChangeRequest $approve,
        private readonly AssessChangeImpact $assess,
        private readonly BuildSolverInput $build,
        private readonly NotifyChangeRequest $notify,
    ) {}

    /**
     * @throws ValidationException si la alternativa no existe o ya no está vigente, o si la solicitud no se puede aprobar
     */
    public function handle(ChangeRequest $request, User $actor, string $alternativeId, ?string $comment = null): ChangeRequest
    {
        Gate::forUser($actor)->authorize('decide', $request);
        $chosen = null;

        $request = $this->approve->approve($request, $actor, $comment, function (ChangeRequest $locked) use ($actor, $alternativeId, &$chosen): void {
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
            $chosen = $item;

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('alternative_chosen')
                ->withProperties(['alternative' => $alternativeId, 'kind' => $item['kind'], 'groups' => array_column($item['groups'], 'code')])
                ->log('Eligió una alternativa del motor para la solicitud de cambio');
        });

        if ($chosen !== null) {
            $this->notify->careerGroupsMoved($request, $chosen);
        }

        return $request;
    }

    /**
     * Si la alternativa sigue vigente (SV-02): la entrada de sus grupos, armada otra vez, tiene la huella guardada, y sus
     * sesiones no dejan choques duros con el validador. La usa también ApplyChosenAlternative (CR-04).
     *
     * @param  array<string, mixed>  $item
     */
    public function isCurrent(ChangeRequest $request, array $item): bool
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

        return $this->assess->simulateSessions($request->term_id, $request->modality_id, ChangeAlternatives::candidates($item))['hard'] === [];
    }
}
