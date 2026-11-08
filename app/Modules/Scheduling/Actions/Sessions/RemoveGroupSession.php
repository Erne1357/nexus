<?php

namespace App\Modules\Scheduling\Actions\Sessions;

use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Rules\SessionRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Quita una sesión de un grupo desde el editor de horarios (tarea 3.5): la sesión completa, con todos sus bloques
 * (supuesto por confirmar). No es catálogo ni bitácora: la fila se borra y lo que tenía queda en la bitácora del grupo
 * (`session_removed`). Con el grupo y la sesión releídos con bloqueo exige un grupo sin cancelar y la carga editable
 * (SessionRules). Si la sesión tenía salón, cancela los préstamos (HC-10) del grupo en ese salón y día que ya no tocan
 * otra de sus sesiones (SyncSessionLoans). Avisa con GroupScheduleChanged después del commit. No sabe de puestos.
 */
final class RemoveGroupSession
{
    public function __construct(
        private readonly SessionRules $rules,
        private readonly SyncSessionLoans $loans,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(GroupSession $session, User $actor): void
    {
        DB::transaction(function () use ($session, $actor): void {
            $group = Group::query()->lockForUpdate()->findOrFail($session->group_id);
            $session = GroupSession::query()->lockForUpdate()->findOrFail($session->getKey());

            $this->rules->ensureEditable($group);

            $session->delete();

            if ($session->room_id !== null) {
                $this->loans->resync($group, Room::withTrashed()->findOrFail($session->room_id), $session->day, $actor);
            }

            activity()
                ->causedBy($actor)
                ->performedOn($group)
                ->event('session_removed')
                ->withChanges(['old' => SessionRules::snapshot($session)])
                ->log("Quitó la sesión de {$this->rules->label($group)} del ".SessionRules::describe($session));

            GroupScheduleChanged::dispatch($group);
        });
    }
}
