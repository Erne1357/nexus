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
 * Borra un rol con soft delete (CF-S2): solo si ningún puesto lo tiene, y nunca uno de fábrica. Deja en la bitácora su
 * nombre y sus claves.
 */
final class DeletePermissionSet
{
    public function __construct(
        private readonly PermissionSetRules $rules,
        private readonly GrantTable $grants,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(PermissionSet $set): void
    {
        DB::transaction(function () use ($set): void {
            $set->setRawAttributes(PermissionSet::query()->lockForUpdate()->findOrFail($set->getKey())->getAttributes(), sync: true);

            if ($set->factory_role !== null) {
                throw ValidationException::withMessages(['permission_set' => "«{$set->name}» es un rol de fábrica: no se borra."]);
            }

            $positions = $this->rules->positionsOf($set);

            if ($positions !== []) {
                $names = implode(', ', array_map(fn (Role $role): string => $role->listLabel(), $positions));

                throw ValidationException::withMessages(['permission_set' => "Quita «{$set->name}» de los puestos que lo tienen antes de borrarlo: {$names}."]);
            }

            $old = ['name' => $set->name, 'permissions' => $this->rules->keysOf($set)];
            $set->delete();

            activity()
                ->performedOn($set)
                ->event(ActivityEvent::Deleted)
                ->withChanges(['old' => $old])
                ->log("Borró el rol «{$set->name}»");
        });

        $this->grants->refresh();
    }
}
