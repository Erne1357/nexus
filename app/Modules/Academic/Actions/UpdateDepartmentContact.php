<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Models\Department;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Pone la ubicación y el contacto de un departamento (`location`, `phone`, `email`, `website`, `map_key`): solo las
 * claves que recibe, con el texto recortado; vacío es null. No toca la clave, el nombre ni el estado. Si nada cambió,
 * no escribe ni registra; si algo cambió, guarda y deja el valor anterior y el nuevo en la bitácora. Quién puede lo
 * decide quien la llama (SyncCampusMap, 1.24.2).
 */
final class UpdateDepartmentContact
{
    private const array COLUMNS = ['location', 'phone', 'email', 'website', 'map_key'];

    /**
     * @param  array{location?: ?string, phone?: ?string, email?: ?string, website?: ?string, map_key?: ?string}  $data
     */
    public function handle(Department $department, array $data): Department
    {
        $data = array_map(function (?string $value): ?string {
            $value = trim((string) $value);

            return $value === '' ? null : $value;
        }, Arr::only($data, self::COLUMNS));

        return DB::transaction(function () use ($department, $data): Department {
            $department->setRawAttributes($department->newQuery()->lockForUpdate()->findOrFail($department->getKey())->getAttributes(), sync: true);
            $old = $department->only(self::COLUMNS);

            $department->fill($data);
            $changed = array_keys($department->getDirty());

            if ($changed === []) {
                return $department;
            }

            $department->save();

            activity()
                ->performedOn($department)
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => $department->only($changed), 'old' => Arr::only($old, $changed)])
                ->log("Actualizó la ubicación y el contacto del departamento {$department->code}");

            return $department;
        });
    }
}
