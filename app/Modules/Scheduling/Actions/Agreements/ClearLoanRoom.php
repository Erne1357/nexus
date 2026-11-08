<?php

namespace App\Modules\Scheduling\Actions\Agreements;

use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;

/**
 * Quita el salón de un préstamo que dejó de valer (rechazado o cancelado) de las sesiones del grupo que usan ese salón y
 * se cruzan con el día y las horas del préstamo (HC-10). Se llama dentro de la transacción de RejectRoomLoan o
 * CancelRoomLoan: deja bitácora `$event` en el grupo si cambió alguna sesión y avisa con GroupScheduleChanged después del
 * commit. Da cuántas sesiones quedaron sin salón.
 */
final class ClearLoanRoom
{
    public function handle(InterdepartmentalAgreement $agreement, User $actor, string $event, string $because): int
    {
        $group = Group::query()->lockForUpdate()->find($agreement->group_id);

        if ($group === null) {
            return 0;
        }

        $sessions = GroupSession::query()
            ->where('group_id', $group->getKey())
            ->where('room_id', $agreement->room_id)
            ->where('day', $agreement->day)
            ->where('starts_at', '<', $agreement->ends_at)
            ->where('ends_at', '>', $agreement->starts_at)
            ->lockForUpdate()
            ->get();

        foreach ($sessions as $session) {
            $session->update(['room_id' => null]);
        }

        if ($sessions->isNotEmpty()) {
            activity()
                ->causedBy($actor)
                ->performedOn($group)
                ->event($event)
                ->withProperties(['agreement_id' => $agreement->getKey(), 'room_id' => $agreement->room_id, 'sessions' => $sessions->pluck('id')->all()])
                ->log("Quitó el salón de {$sessions->count()} sesión(es) de {$group->code} {$because}");
        }

        GroupScheduleChanged::dispatch($group);

        return $sessions->count();
    }
}
