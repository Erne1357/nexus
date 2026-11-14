<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Authorization\GrantTable;
use App\Modules\Core\Models\PermissionSet;
use App\Modules\Core\Rules\PermissionSetRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * «Aplicar» los permisos nuevos de fábrica a un rol de fábrica editado (CF-02): agrega lo que el código concede a su
 * puesto y que no estaba en su línea base ni en su lista, y deja como línea base lo que el código concede hoy. Lo que
 * agrega debe poderse dar a los otros puestos que tienen el rol (CF-04). Sin nada nuevo, no escribe ni registra. Deja
 * en la bitácora las claves antes y después.
 */
final class ApplyFactoryUpdates
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
            $added = $this->rules->newFactoryKeys($set);

            if ($added === [] || $set->factory_role === null) {
                return $set;
            }

            $this->rules->ensureGrantable($this->rules->positionsOf($set), $added, 'permission_set');
            $old = $this->rules->keysOf($set);
            $new = array_values(array_unique([...$old, ...$added]));
            sort($new);

            $this->rules->replaceKeys($set, $new);
            $set->forceFill(['factory_baseline' => $this->rules->factoryKeys($set->factory_role)])->save();

            activity()
                ->performedOn($set)
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => ['permissions' => $new], 'old' => ['permissions' => $old]])
                ->log('Aplicó '.count($added).' permisos nuevos de fábrica al rol «'.$set->name.'»');

            return $set;
        });

        $this->grants->refresh();

        return $set;
    }
}
