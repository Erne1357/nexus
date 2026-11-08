<?php

namespace App\Modules\Staffing\Actions\ChangeRequests;

use App\Modules\AcademicLoad\Actions\Transitions\StartAdjusting;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\User;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Notifications\ChangeRequestApproved;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aprueba una solicitud de cambio pendiente (tarea 3.11). Decide el jefe del departamento (ChangeRequestPolicy::decide,
 * antes de llamarla). Si la carga del departamento está en asignación, pasa a ajustes (confirmado el 7 de noviembre de
 * 2026); en cualquier otro estado la carga no se toca. Deja bitácora y, después del commit, avisa al solicitante y a
 * quien debe aplicarla.
 */
final class ApproveChangeRequest
{
    public function __construct(private readonly StartAdjusting $startAdjusting, private readonly NotifyChangeRequest $notify) {}

    /**
     * @throws ValidationException
     */
    public function handle(ChangeRequest $request, User $actor, ?string $comment = null): ChangeRequest
    {
        $comment = $comment === null || trim($comment) === '' ? null : trim($comment);

        $request = DB::transaction(function () use ($request, $actor, $comment): ChangeRequest {
            $request->setRawAttributes($request->newQuery()->lockForUpdate()->findOrFail($request->getKey())->getAttributes(), sync: true);

            if ($request->status !== ChangeRequestStatus::Pending) {
                throw ValidationException::withMessages(['status' => 'La solicitud ya no está pendiente.']);
            }

            $request->update(['status' => ChangeRequestStatus::Approved, 'decided_by' => $actor->getKey(), 'decided_at' => now(), 'decision_comment' => $comment]);

            $load = AcademicLoad::query()
                ->where('term_id', $request->term_id)
                ->where('campus_id', $request->campus_id)
                ->where('department_id', $request->department_id)
                ->first();

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
        });

        $this->notify->requester($request, ChangeRequestApproved::class);
        $this->notify->appliers($request);

        return $request;
    }
}
