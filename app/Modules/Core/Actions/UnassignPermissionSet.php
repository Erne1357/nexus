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
 * Le quita un rol a un puesto (CF-01); también su rol de fábrica, que «Restablecer puesto» le regresa (CF-02). El admin
 * no se edita (CF-S1). Si el puesto no lo tiene, no escribe ni registra. Deja en la bitácora, sobre el rol, los roles y
 * los permisos sueltos del puesto antes y después.
 */
final class UnassignPermissionSet
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
            $old = $this->rules->positionState($role);
            $removed = DB::table('position_permission_sets')->where('role', $role->value)->where('permission_set_id', $set->getKey())->delete();

            if ($removed === 0) {
                return;
            }

            activity()
                ->performedOn($set)
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => $this->rules->positionState($role), 'old' => $old])
                ->withProperties(['position' => $role->value])
                ->log("Quitó el rol «{$set->name}» del puesto {$role->listLabel()}");
        });

        $this->grants->refresh();
    }
}
