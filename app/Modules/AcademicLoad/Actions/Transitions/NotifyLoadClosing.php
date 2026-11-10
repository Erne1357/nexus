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
 * Avisa a la campana según la transición de la carga (tarea 4.2). Los destinatarios son quienes tienen el permiso en el
 * campus y el departamento de la carga (el admin no recibe, no es titular):
 *
 * - a ajustes desde cerrada, aprobada o publicada (reabierta o regresada con comentarios): los titulares de LoadClose
 *   (el jefe), con el motivo, que es el comentario de esa transición (LoadReopened);
 * - a cerrada en un campus que exige el visto bueno: los titulares de LoadApprove (LoadAwaitingApproval);
 * - a aprobada: los titulares de LoadClose (LoadApproved).
 *
 * Cualquier otra transición no avisa. Da a cuántos usuarios avisó.
 */
final class NotifyLoadClosing
{
    private const array REOPENED_FROM = [LoadStatus::Closed, LoadStatus::Approved, LoadStatus::Published];

    public function __construct(private readonly RecipientsQuery $recipients, private readonly NotifyHolders $notifyHolders) {}

    public function handle(AcademicLoad $load, LoadStatus $from, LoadStatus $to): int
    {
        $load->loadMissing(['department', 'campus']);

        [$permission, $notification] = match (true) {
            $to === LoadStatus::Adjusting && in_array($from, self::REOPENED_FROM, true) => [AcademicLoadPermission::LoadClose, new LoadReopened($load, $load->department, $this->reason($load, $from))],
            $to === LoadStatus::Closed && $load->campus->profile->requiresSubdireccionApproval => [AcademicLoadPermission::LoadApprove, new LoadAwaitingApproval($load, $load->department)],
            $to === LoadStatus::Approved => [AcademicLoadPermission::LoadClose, new LoadApproved($load, $load->department)],
            default => [null, null],
        };

        if ($permission === null) {
            return 0;
        }

        return $this->send($permission, $load, $notification);
    }

    private function send(Permission $permission, AcademicLoad $load, LoadClosingNotification $notification): int
    {
        $holders = $this->recipients->holders($permission, Target::of(campus: $load->campus_id, unit: $load->department_id));

        return $this->notifyHolders->handle($holders, $notification)->count();
    }

    /**
     * El comentario de la última transición de esa carga que salió de `$from` hacia ajustes.
     */
    private function reason(AcademicLoad $load, LoadStatus $from): ?string
    {
        return $load->transitions()
            ->where('from_status', $from)->where('to_status', LoadStatus::Adjusting)
            ->latest('created_at')->latest('id')
            ->value('comment');
    }
}
