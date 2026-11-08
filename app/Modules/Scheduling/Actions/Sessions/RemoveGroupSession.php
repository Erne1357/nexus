<?php

namespace App\Modules\Scheduling\Actions\Sessions;

use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Rules\LoadEditability;
use App\Modules\Scheduling\Rules\SessionRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Quita una sesión de un grupo desde el editor de horarios (tarea 3.5): la sesión completa, con todos sus bloques
 * (supuesto por confirmar). No es catálogo ni bitácora: la fila se borra y lo que tenía queda en la bitácora del grupo
 * (`session_removed`). Con el grupo y la sesión releídos con bloqueo exige un grupo sin cancelar y la carga editable
 * (SessionRules). El grupo se nombra en la bitácora con su materia y su clave (SessionRules::label()). Avisa con
 * GroupScheduleChanged después del commit. No sabe de puestos.
 */
final class RemoveGroupSession
{
    public function __construct(
        private readonly SessionRules $rules,
        private readonly LoadEditability $editability,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(GroupSession $session, User $actor): void
    {
        $this->editability->guard(fn () => DB::transaction(function () use ($session, $actor): void {
            $group = Group::query()->findOrFail($session->group_id);
            $this->editability->lockLoad($group);
            $group = Group::query()->lockForUpdate()->findOrFail($group->id);
            $session = GroupSession::query()->lockForUpdate()->findOrFail($session->getKey());

            $this->rules->ensureEditable($group);

            $session->delete();
            $label = SessionRules::label($group);

            activity()
                ->causedBy($actor)
                ->performedOn($group)
                ->event('session_removed')
                ->withChanges(['old' => SessionRules::snapshot($session)])
                ->log("Quitó la sesión de {$label} del ".SessionRules::describe($session));

            GroupScheduleChanged::dispatch($group);
        }));
    }
}
