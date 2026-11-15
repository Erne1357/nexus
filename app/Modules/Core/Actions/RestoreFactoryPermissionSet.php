<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Authorization\GrantTable;
use App\Modules\Core\Models\PermissionSet;
use App\Modules\Core\Rules\PermissionSetRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * «Restablecer» un rol de fábrica (CF-02): vuelve a seguir lo que el código concede a su puesto y deja de guardar su
 * propia lista. El nombre y la descripción no cambian (supuesto por confirmar). Lo que vuelve a traer debe poderse dar
 * a los otros puestos que tienen el rol (CF-04). Solo los roles de fábrica se restablecen; si ya sigue al código, no
 * escribe ni registra. Deja en la bitácora las claves antes y después.
 */
final class RestoreFactoryPermissionSet
{
    public function __construct(
        private readonly PermissionSetRules $rules,
        private readonly GrantTable $grants,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(PermissionSet $set): PermissionSet
    {
        $set = DB::transaction(function () use ($set): PermissionSet {
            $set->setRawAttributes(PermissionSet::query()->lockForUpdate()->findOrFail($set->getKey())->getAttributes(), sync: true);

            if ($set->factory_role === null) {
                throw ValidationException::withMessages(['permission_set' => "«{$set->name}» no es un rol de fábrica: no se restablece."]);
            }

            if ($set->follows_factory) {
                return $set;
            }

            $old = $this->rules->keysOf($set);
            $new = $this->rules->factoryKeys($set->factory_role);
            $this->rules->ensureGrantable($this->rules->positionsOf($set), array_values(array_diff($new, $old)), 'permission_set');

            DB::table('permission_set_permissions')->where('permission_set_id', $set->getKey())->delete();
            $set->forceFill(['follows_factory' => true, 'factory_baseline' => null])->save();

            activity()
                ->performedOn($set)
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => ['follows_factory' => true, 'permissions' => $new], 'old' => ['follows_factory' => false, 'permissions' => $old]])
                ->log("Restableció el rol de fábrica «{$set->name}»");

            return $set;
        });

        $this->grants->refresh();

        return $set;
    }
}
