<?php

namespace App\Modules\Staffing\Actions\ChangeRequests;

use App\Modules\AcademicLoad\Actions\Transitions\ReturnToStaffing;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\User;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Notifications\ChangeRequestApplied;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Marca como aplicada una solicitud aprobada (tarea 3.11), cuando quien debía ya hizo el cambio en el horario:
 * el coordinador de la carrera dueña si es de horario y el jefe del departamento si es de salón (ChangeRequestPolicy::apply,
 * antes de llamarla; confirmado el 7 de noviembre de 2026).
 *
 * En una transacción la deja aplicada con quién y cuándo, y deja bitácora. Después, ya fuera de ella, si la carga del
 * departamento está en ajustes y no queda otra solicitud aprobada sin aplicar de esa carga, la regresa a asignación con
 * ReturnToStaffing. Si Scheduling la veta (choques duros), la solicitud queda aplicada y la carga en ajustes
 * (supuesto por confirmar): los motivos vuelven en el resultado para que la pantalla los muestre. Avisa al solicitante.
 */
final class MarkChangeRequestApplied
{
    public function __construct(private readonly ReturnToStaffing $returnToStaffing, private readonly NotifyChangeRequest $notify) {}

    /**
     * @throws ValidationException
     */
    public function handle(ChangeRequest $request, User $actor): AppliedChangeRequest
    {
        $request = DB::transaction(function () use ($request, $actor): ChangeRequest {
            $request->setRawAttributes($request->newQuery()->lockForUpdate()->findOrFail($request->getKey())->getAttributes(), sync: true);

            if ($request->status !== ChangeRequestStatus::Approved) {
                throw ValidationException::withMessages(['status' => 'La solicitud no está aprobada.']);
            }

            $request->update(['status' => ChangeRequestStatus::Applied, 'applied_by' => $actor->getKey(), 'applied_at' => now()]);

            activity()
                ->performedOn($request)
                ->causedBy($actor)
                ->event('applied')
                ->withChanges(['old' => ['status' => 'approved'], 'attributes' => ['status' => 'applied']])
                ->log('Marcó la solicitud de cambio como aplicada');

            return $request;
        });

        $this->notify->requester($request, ChangeRequestApplied::class);

        return $this->returnLoad($request, $actor);
    }

    private function returnLoad(ChangeRequest $request, User $actor): AppliedChangeRequest
    {
        $load = AcademicLoad::query()
            ->ofDepartment($request->term_id, $request->modality_id, $request->department_id)
            ->first();

        $pending = ChangeRequest::query()
            ->where('term_id', $request->term_id)
            ->where('modality_id', $request->modality_id)
            ->where('department_id', $request->department_id)
            ->where('status', ChangeRequestStatus::Approved)
            ->exists();

        if ($load?->status !== LoadStatus::Adjusting || $pending) {
            return new AppliedChangeRequest($request, false);
        }

        try {
            $this->returnToStaffing->handle($load, $actor, 'Se aplicaron los cambios aprobados');
        } catch (ValidationException $e) {
            return new AppliedChangeRequest($request, false, array_values($e->errors()['status'] ?? []));
        }

        return new AppliedChangeRequest($request, true);
    }
}
