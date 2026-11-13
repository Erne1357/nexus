<?php

namespace App\Modules\AcademicLoad\Actions\Transitions;

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Notifications\LoadApproved;
use App\Modules\AcademicLoad\Notifications\LoadAwaitingApproval;
use App\Modules\AcademicLoad\Notifications\LoadClosingNotification;
use App\Modules\AcademicLoad\Notifications\LoadReopened;
use App\Modules\Core\Actions\NotifyHolders;
use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Queries\RecipientsQuery;

/**
 * Avisa a la campana según la transición de la carga (tarea 4.2). Los destinatarios son quienes tienen el permiso en la
 * modalidad y el departamento de la carga (el admin no recibe, no es titular):
 *
 * - a ajustes desde cerrada, aprobada o publicada (reabierta o regresada con comentarios): los titulares de
 *   LoadStartStaffing (el jefe), con el motivo, que es el comentario de esa transición (LoadReopened);
 * - a cerrada en una modalidad que exige el visto bueno: los titulares de LoadApprove (LoadAwaitingApproval);
 * - a aprobada: los titulares de LoadStartStaffing (LoadApproved).
 *
 * Los avisos al jefe van por LoadStartStaffing y no por LoadClose porque Subdirección también cierra (confirmado el 10
 * de noviembre de 2026) y no debe recibir el aviso de su propia reapertura; quien regresa la carga a asignación y la
 * vuelve a cerrar es el jefe del departamento. Cualquier otra transición no avisa. Da a cuántos usuarios avisó.
 */
final class NotifyLoadClosing
{
    private const array REOPENED_FROM = [LoadStatus::Closed, LoadStatus::Approved, LoadStatus::Published];

    public function __construct(private readonly RecipientsQuery $recipients, private readonly NotifyHolders $notifyHolders) {}

    public function handle(AcademicLoad $load, LoadStatus $from, LoadStatus $to, ?string $reason = null): int
    {
        $load->loadMissing(['department', 'modality']);

        [$permission, $notification] = match (true) {
            $to === LoadStatus::Adjusting && in_array($from, self::REOPENED_FROM, true) => [AcademicLoadPermission::LoadStartStaffing, new LoadReopened($load, $load->department, $reason)],
            $to === LoadStatus::Closed && $load->modality->profile->requiresSubdireccionApproval => [AcademicLoadPermission::LoadApprove, new LoadAwaitingApproval($load, $load->department)],
            $to === LoadStatus::Approved => [AcademicLoadPermission::LoadStartStaffing, new LoadApproved($load, $load->department)],
            default => [null, null],
        };

        if ($permission === null) {
            return 0;
        }

        return $this->send($permission, $load, $notification);
    }

    private function send(Permission $permission, AcademicLoad $load, LoadClosingNotification $notification): int
    {
        $holders = $this->recipients->holders($permission, Target::of(modality: $load->modality_id, unit: $load->department_id));

        return $this->notifyHolders->handle($holders, $notification)->count();
    }
}
