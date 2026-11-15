<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Authorization\GrantTable;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\PermissionSet;
use App\Modules\Core\Rules\PermissionSetRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Le da un rol a un puesto (CF-01). El admin no se edita (CF-S1), y cada permiso del rol debe poderse dar al puesto:
 * ninguno candado (CF-03) ni que amplíe su alcance (CF-04); lo de fábrica del puesto siempre vale. Un rol desactivado
 * se puede dar, pero no aporta hasta que se active (CF-S2).
 *
 * Bloquea el rol mientras revisa, para que nadie le cambie los permisos a la vez (UpdatePermissionSet lo bloquea
 * también). Si el puesto ya lo tiene, no escribe ni registra. Deja en la bitácora, sobre el rol, los roles y los
 * permisos sueltos del puesto antes y después.
 */
final class AssignPermissionSet
{
    public function __construct(
        private readonly PermissionSetRules $rules,
        private readonly GrantTable $grants,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Role $role, PermissionSet $set): void
    {
        $this->rules->ensureEditable($role);

        DB::transaction(function () use ($role, $set): void {
            $set->setRawAttributes(PermissionSet::query()->lockForUpdate()->findOrFail($set->getKey())->getAttributes(), sync: true);

            if (in_array($role, $this->rules->positionsOf($set), true)) {
                return;
            }

            $this->rules->ensureGrantable([$role], $this->rules->keysOf($set), 'permission_set');
            $old = $this->rules->positionState($role);

            if (DB::table('position_permission_sets')->insertOrIgnore(['role' => $role->value, 'permission_set_id' => $set->getKey()]) === 0) {
                return;
            }

            activity()
                ->performedOn($set)
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => $this->rules->positionState($role), 'old' => $old])
                ->withProperties(['position' => $role->value])
                ->log("Dio el rol «{$set->name}» al puesto {$role->listLabel()}");
        });

        $this->grants->refresh();
    }
}
