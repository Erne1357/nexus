<?php

namespace App\Modules\Staffing\Actions\ChangeRequests;

use App\Modules\AcademicLoad\Actions\Transitions\StartAdjusting;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Core\Models\User;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Notifications\ChangeRequestApproved;
use App\Modules\Staffing\Rules\ChangeRequestRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aprueba una solicitud de cambio pendiente (tarea 3.11), con la carga del departamento en asignación o ajustes (P5). Decide el jefe del departamento (ChangeRequestPolicy::decide,
 * antes de llamarla). Si la carga del departamento está en asignación, pasa a ajustes (confirmado el 7 de noviembre de
 * 2026); en cualquier otro estado la carga no se toca. Deja bitácora y, después del commit, avisa al solicitante y a
 * quien debe aplicarla.
 *
 * Candados: LocksChangeRequestScope (modalidad SHARE, carga UPDATE, grupo UPDATE, solicitud), el mismo orden que Reject y
 * Apply y que el editor. Con ellos, el editor no cambia las sesiones entre la simulación del bloqueo de choques (CR-01) y
 * el commit. StartAdjusting llama a TransitionLoad dentro de esta transacción: vuelve a pedir la modalidad y la carga
 * que ya se tienen, en el mismo orden.
 */
final class ApproveChangeRequest
{
    public function __construct(private readonly StartAdjusting $startAdjusting, private readonly NotifyChangeRequest $notify, private readonly AssessChangeImpact $assess, private readonly LocksChangeRequestScope $locks) {}

    /**
     * @throws ValidationException
     */
    public function handle(ChangeRequest $request, User $actor, ?string $comment = null): ChangeRequest
    {
        $comment = $comment === null || trim($comment) === '' ? null : trim($comment);

        $request = $this->locks->guard(fn (): ChangeRequest => DB::transaction(function () use ($request, $actor, $comment): ChangeRequest {
            $load = $this->locks->lock($request);

            if ($request->status !== ChangeRequestStatus::Pending) {
                throw ValidationException::withMessages(['status' => 'La solicitud ya no está pendiente.']);
            }

            if (! ChangeRequestRules::acceptsRequests($load?->status)) {
                throw ValidationException::withMessages(['status' => 'La carga del departamento está en '.($load?->status->label() ?? 'ningún estado').': solo se deciden solicitudes con la carga en asignación o en ajustes.']);
            }

            $this->blockHardClashes($request);

            $request->update(['status' => ChangeRequestStatus::Approved, 'decided_by' => $actor->getKey(), 'decided_at' => now(), 'decision_comment' => $comment]);

            if ($load?->status === LoadStatus::Staffing) {
                $this->startAdjusting->handle($load, $actor, 'Solicitud de cambio aprobada');
            }

            activity()
                ->performedOn($request)
                ->causedBy($actor)
                ->event('approved')
                ->withChanges(['old' => ['status' => 'pending'], 'attributes' => ['status' => 'approved']])
                ->withProperties(['comment' => $comment])
                ->log('Aprobó la solicitud de cambio');

            return $request;
        }));

        $this->notify->requester($request, ChangeRequestApproved::class);
        $this->notify->appliers($request);

        return $request;
    }

    /**
     * CR-01 (confirmado el 18 de noviembre de 2026): no se aprueba un cambio que deja choques duros. Se simula en el
     * momento, no con el impacto guardado, porque el horario pudo cambiar. Una solicitud que no se puede simular (solo
     * texto) se aprueba (supuesto por confirmar): al aplicarla, el validador de horarios sigue impidiendo los choques.
     *
     * @throws ValidationException
     */
    private function blockHardClashes(ChangeRequest $request): void
    {
        $impact = $this->assess->simulate($request);
        $clashes = array_sum(array_map(fn (array $rule): int => count($rule['messages']), $impact['hard']));

        if ($clashes > 0) {
            throw ValidationException::withMessages(['status' => "No se puede aprobar: el cambio deja {$clashes} ".($clashes === 1 ? 'choque duro' : 'choques duros').'. Recházalo o pide alternativas al motor.']);
        }
    }
}
