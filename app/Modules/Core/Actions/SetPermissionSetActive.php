<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Authorization\GrantTable;
use App\Modules\Core\Models\PermissionSet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Desactiva o activa un rol (CF-S2): desactivado deja de aportar en todos sus puestos, pero sigue asignado. Un rol de
 * fábrica no se desactiva, pero sí se activa (si quedó desactivado por otro camino). Relee el rol con bloqueo; si el
 * estado no cambia, no escribe ni registra.
 */
final class SetPermissionSetActive
{
    public function __construct(private readonly GrantTable $grants) {}

    /**
     * @throws ValidationException
     */
    public function handle(PermissionSet $set, bool $active): PermissionSet
    {
        $set = DB::transaction(function () use ($set, $active): PermissionSet {
            $set->setRawAttributes(PermissionSet::query()->lockForUpdate()->findOrFail($set->getKey())->getAttributes(), sync: true);

            if ($set->is_active === $active) {
                return $set;
            }

            if (! $active && $set->factory_role !== null) {
                throw ValidationException::withMessages(['permission_set' => "«{$set->name}» es un rol de fábrica: no se desactiva."]);
            }

            $set->update(['is_active' => $active]);

            activity()
                ->performedOn($set)
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => ['is_active' => $active], 'old' => ['is_active' => ! $active]])
                ->log(($active ? 'Activó' : 'Desactivó')." el rol «{$set->name}»");

            return $set;
        });

        $this->grants->refresh();

        return $set;
    }
}
