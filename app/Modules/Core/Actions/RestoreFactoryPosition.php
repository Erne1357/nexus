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
 * «Restablecer puesto» (CF-02): el puesto queda solo con su rol de fábrica y sin permisos sueltos. No toca el rol de
 * fábrica: si alguien lo editó, se restablece aparte (RestoreFactoryPermissionSet). Si el puesto ya no lo tenía, el rol
 * se bloquea y se revisa como al darlo (AssignPermissionSet, CF-04): pudo cambiar mientras nadie lo tenía. El admin no
 * se edita (CF-S1). Si el puesto ya estaba así, no escribe ni registra. Deja en la bitácora sus roles y sus permisos sueltos antes y
 * después.
 */
final class RestoreFactoryPosition
{
    public function __construct(
        private readonly PermissionSetRules $rules,
        private readonly GrantTable $grants,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Role $role): void
    {
        $this->rules->ensureEditable($role);

        DB::transaction(function () use ($role): void {
            $factory = PermissionSet::query()->where('factory_role', $role->value)->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['role' => "El puesto {$role->listLabel()} no tiene rol de fábrica."]);

            if (! in_array($role, $this->rules->positionsOf($factory), true)) {
                $this->rules->ensureGrantable([$role], $this->rules->keysOf($factory), 'role');
            }

            $old = $this->rules->positionState($role);

            DB::table('position_permission_sets')->where('role', $role->value)->where('permission_set_id', '<>', $factory->getKey())->delete();
            DB::table('position_permission_sets')->insertOrIgnore(['role' => $role->value, 'permission_set_id' => $factory->getKey()]);
            DB::table('position_permissions')->where('role', $role->value)->delete();
            $new = $this->rules->positionState($role);

            if ($new === $old) {
                return;
            }

            activity()
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => $new, 'old' => $old])
                ->withProperties(['position' => $role->value])
                ->log("Restableció el puesto {$role->listLabel()}");
        });

        $this->grants->refresh();
    }
}
