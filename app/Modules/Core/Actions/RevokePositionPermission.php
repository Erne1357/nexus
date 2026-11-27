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
 * Le quita a un puesto un permiso suelto (CF-01); lo que le dan sus roles no cambia. El admin no se edita (CF-S1). Si
 * no lo tiene suelto, no escribe ni registra. Deja en la bitácora los permisos sueltos y los roles del puesto antes y
 * después. Recibe también la clave como texto: un suelto cuya clave ya no declara ningún módulo (la quitó un
 * despliegue) no aporta nada, pero se tiene que poder quitar.
 */
final class RevokePositionPermission
{
    public function __construct(
        private readonly PermissionSetRules $rules,
        private readonly GrantTable $grants,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Role $role, Permission|string $permission): void
    {
        $this->rules->ensureEditable($role);
        $key = $permission instanceof Permission ? (string) $permission->value : $permission;
        $label = $permission instanceof Permission ? $permission->label() : $key;

        DB::transaction(function () use ($role, $key, $label): void {
            $old = $this->rules->positionState($role);

            if (DB::table('position_permissions')->where('role', $role->value)->where('permission', $key)->delete() === 0) {
                return;
            }

            activity()
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => $this->rules->positionState($role), 'old' => $old])
                ->withProperties(['position' => $role->value])
                ->log("Quitó el permiso «{$label}» del puesto {$role->listLabel()}");
        });

        $this->grants->refresh();
    }
}
