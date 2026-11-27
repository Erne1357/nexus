<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Authorization\GrantTable;
use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Rules\PermissionSetRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Le da a un puesto un permiso suelto (CF-01). El admin no se edita (CF-S1), y el permiso no puede ser candado (CF-03,
 * salvo la excepción de fábrica de ese puesto) ni ampliar el alcance del puesto (CF-04); lo de fábrica del puesto
 * siempre vale. Si ya lo tiene suelto, no escribe
 * ni registra. Deja en la bitácora los permisos sueltos y los roles del puesto antes y después.
 */
final class GrantPositionPermission
{
    public function __construct(
        private readonly PermissionSetRules $rules,
        private readonly GrantTable $grants,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Role $role, Permission $permission): void
    {
        $this->rules->ensureEditable($role);
        [$key] = $this->rules->permissionKeys([(string) $permission->value], 'permission', $role);
        $this->rules->ensureGrantable([$role], [$key], 'permission');

        DB::transaction(function () use ($role, $key, $permission): void {
            $old = $this->rules->positionState($role);

            if (DB::table('position_permissions')->insertOrIgnore(['role' => $role->value, 'permission' => $key]) === 0) {
                return;
            }

            activity()
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => $this->rules->positionState($role), 'old' => $old])
                ->withProperties(['position' => $role->value])
                ->log("Dio el permiso «{$permission->label()}» al puesto {$role->listLabel()}");
        });

        $this->grants->refresh();
    }
}
