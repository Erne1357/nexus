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
 * Le da a un puesto varios permisos sueltos a la vez («Agregar permisos sueltos», CF-01), todos o ninguno, en una sola
 * transacción. Las reglas de GrantPositionPermission: el admin no se edita (CF-S1), y ninguno puede ser candado (CF-03)
 * ni ampliar el alcance del puesto (CF-04); lo de fábrica del puesto siempre vale. Si alguno no se puede, no da ninguno
 * y los motivos salen juntos sobre `permissions`. Lo que ya tenía suelto no se escribe ni se registra; cada permiso que
 * da deja su entrada en la bitácora, con los permisos sueltos y los roles del puesto antes y después.
 */
final class GrantPositionPermissions
{
    public function __construct(
        private readonly PermissionSetRules $rules,
        private readonly GrantTable $grants,
    ) {}

    /**
     * @param  list<Permission>  $permissions
     *
     * @throws ValidationException
     */
    public function handle(Role $role, array $permissions): void
    {
        $this->rules->ensureEditable($role);

        $labels = [];

        foreach ($permissions as $permission) {
            $labels[(string) $permission->value] = $permission->label();
        }

        $keys = array_keys($labels);
        sort($keys);

        if ($keys === []) {
            throw ValidationException::withMessages(['permissions' => 'Elige al menos un permiso.']);
        }

        $this->rules->ensureGrantable([$role], $keys, 'permissions');
        $keys = $this->rules->permissionKeys($keys);

        DB::transaction(function () use ($role, $keys, $labels): void {
            foreach ($keys as $key) {
                $old = $this->rules->positionState($role);

                if (DB::table('position_permissions')->insertOrIgnore(['role' => $role->value, 'permission' => $key]) === 0) {
                    continue;
                }

                activity()
                    ->event(ActivityEvent::Updated)
                    ->withChanges(['attributes' => $this->rules->positionState($role), 'old' => $old])
                    ->withProperties(['position' => $role->value])
                    ->log("Dio el permiso «{$labels[$key]}» al puesto {$role->listLabel()}");
            }
        });

        $this->grants->refresh();
    }
}
