<?php

namespace App\Modules\Staffing\Actions\ChangeRequests;

use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\User;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Notifications\ChangeRequestRejected;
use App\Modules\Staffing\Rules\ChangeRequestRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rechaza una solicitud de cambio pendiente (tarea 3.11), con la carga del departamento en asignación o ajustes (P5). Decide el jefe del departamento (ChangeRequestPolicy::decide,
 * antes de llamarla) y el comentario es obligatorio (supuesto por confirmar). La carga no cambia de estado. Deja
 * bitácora y, después del commit, avisa al solicitante.
 */
final class RejectChangeRequest
{
    public function __construct(private readonly NotifyChangeRequest $notify) {}

    /**
     * @throws ValidationException
     */
    public function handle(ChangeRequest $request, User $actor, string $comment): ChangeRequest
    {
        $comment = trim($comment);

        if ($comment === '') {
            throw ValidationException::withMessages(['comment' => 'Escribe por qué se rechaza la solicitud.']);
        }

        $request = DB::transaction(function () use ($request, $actor, $comment): ChangeRequest {
            $request->setRawAttributes($request->newQuery()->lockForUpdate()->findOrFail($request->getKey())->getAttributes(), sync: true);

            if ($request->status !== ChangeRequestStatus::Pending) {
                throw ValidationException::withMessages(['status' => 'La solicitud ya no está pendiente.']);
            }

            $load = AcademicLoad::query()
                ->ofDepartment($request->term_id, $request->modality_id, $request->department_id)
                ->lockForUpdate()
                ->first();

            if (! ChangeRequestRules::acceptsRequests($load?->status)) {
                throw ValidationException::withMessages(['status' => 'La carga del departamento está en '.($load?->status->label() ?? 'ningún estado').': solo se deciden solicitudes con la carga en asignación o en ajustes.']);
            }

            $request->update(['status' => ChangeRequestStatus::Rejected, 'decided_by' => $actor->getKey(), 'decided_at' => now(), 'decision_comment' => $comment]);

            activity()
                ->performedOn($request)
                ->causedBy($actor)
                ->event('rejected')
                ->withChanges(['old' => ['status' => 'pending'], 'attributes' => ['status' => 'rejected']])
                ->withProperties(['comment' => $comment])
                ->log('Rechazó la solicitud de cambio');

            return $request;
        });

        $this->notify->requester($request, ChangeRequestRejected::class);

        return $request;
    }
}
