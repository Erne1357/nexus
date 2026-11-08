<?php

namespace App\Modules\Staffing\Actions\ChangeRequests;

use App\Modules\Core\Actions\NotifyHolders;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Queries\RecipientsQuery;
use App\Modules\Staffing\Enums\ChangeRequestKind;
use App\Modules\Staffing\Enums\StaffingPermission;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Notifications\ChangeRequested;
use App\Modules\Staffing\Notifications\ChangeRequestNotification;
use App\Modules\Staffing\Notifications\ChangeRequestPendingApplication;

/**
 * Los avisos del flujo de una solicitud de cambio (tarea 3.11): a los jefes al pedirla, al solicitante al decidirla
 * o aplicarla, y a quien debe aplicarla al aprobarla (el coordinador de la carrera dueña si es de horario; el jefe
 * del departamento si es de salón). Las Actions lo llaman después del commit.
 */
final class NotifyChangeRequest
{
    public function __construct(private readonly RecipientsQuery $recipients, private readonly NotifyHolders $notifyHolders) {}

    public function requested(ChangeRequest $request): void
    {
        $heads = $this->recipients->holders(StaffingPermission::ChangeRequestDecide, Target::of(campus: $request->campus_id, unit: $request->department_id));

        $this->notifyHolders->handle($heads, new ChangeRequested($request));
    }

    /**
     * Al solicitante. Sin puesto (`data.role`): pidió como docente o como coordinador y el aviso no cambia de perfil.
     *
     * @param  class-string<ChangeRequestNotification>  $notification
     */
    public function requester(ChangeRequest $request, string $notification): void
    {
        $request->loadMissing('requestedBy');

        $request->requestedBy->notify(new $notification($request));
    }

    public function appliers(ChangeRequest $request): void
    {
        $request->loadMissing('group');

        $holders = $request->kind === ChangeRequestKind::Schedule
            ? $this->recipients->holders(StaffingPermission::ChangeRequestApplySchedule, Target::of(campus: $request->campus_id, career: $request->group->owner_career_id))
            : $this->recipients->holders(StaffingPermission::ChangeRequestApplyRoom, Target::of(campus: $request->campus_id, unit: $request->department_id));

        $this->notifyHolders->handle($holders, new ChangeRequestPendingApplication($request));
    }
}
