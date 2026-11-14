<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\PermissionSet;
use App\Modules\Core\Rules\PermissionSetRules;
use Illuminate\Support\Facades\DB;

/**
 * Los roles para «Puestos y permisos» (CF-01, CF-02, CF-S2): los no borrados, primero los de fábrica en el orden de
 * sus puestos y luego los propios por nombre, cada uno con los puestos que lo tienen, sus permisos agrupados por
 * módulo y, si es de fábrica y ya no sigue al código, los permisos nuevos de fábrica que se pueden aplicar.
 */
final class PermissionSetsQuery
{
    public function __construct(
        private readonly PermissionRegistry $registry,
        private readonly PermissionSetRules $rules,
    ) {}

    /**
     * @return list<array{
     *     id: string,
     *     name: string,
     *     description: string|null,
     *     is_active: bool,
     *     factory_role: Role|null,
     *     follows_factory: bool,
     *     positions: list<Role>,
     *     permissions: array<string, list<array{key: string, label: string}>>,
     *     permission_count: int,
     *     new_factory: list<string>,
     * }>
     */
    public function all(): array
    {
        $sets = PermissionSet::query()->orderBy('name')->get();
        $own = DB::table('permission_set_permissions')->whereIn('permission_set_id', $sets->modelKeys())->orderBy('permission')->get()->groupBy('permission_set_id');
        $positions = DB::table('position_permission_sets')->whereIn('permission_set_id', $sets->modelKeys())->get()->groupBy('permission_set_id');
        $order = array_flip(array_map(fn (Role $role): string => $role->value, Role::cases()));

        return $sets
            ->sortBy(fn (PermissionSet $set): array => [$set->factory_role === null ? 1 : 0, $set->factory_role !== null ? $order[$set->factory_role->value] : 0, mb_strtolower($set->name)])
            ->values()
            ->map(function (PermissionSet $set) use ($own, $positions): array {
                $keys = $set->follows_factory && $set->factory_role !== null
                    ? $this->rules->factoryKeys($set->factory_role)
                    : ($own->get($set->id)?->pluck('permission')->all() ?? []);
                $held = $positions->get($set->id)?->pluck('role')->all() ?? [];

                return [
                    'id' => $set->id,
                    'name' => $set->name,
                    'description' => $set->description,
                    'is_active' => $set->is_active,
                    'factory_role' => $set->factory_role,
                    'follows_factory' => $set->follows_factory,
                    'positions' => array_values(array_filter(Role::cases(), fn (Role $role): bool => in_array($role->value, $held, true))),
                    'permissions' => $this->byModule($keys),
                    'permission_count' => count($keys),
                    'new_factory' => $this->rules->newFactoryKeys($set),
                ];
            })
            ->all();
    }

    /**
     * El rol no borrado con ese id, para editarlo; 404 si no existe o el id no es un uuid.
     */
    public function findOrFail(string $id): PermissionSet
    {
        return KeyLookup::findOrFail(PermissionSet::query(), $id);
    }

    /**
     * Las claves agrupadas por módulo (el primer tramo de la clave), con su etiqueta; una clave que ningún módulo
     * declara sale con la clave como etiqueta.
     *
     * @param  list<string>  $keys
     * @return array<string, list<array{key: string, label: string}>>
     */
    private function byModule(array $keys): array
    {
        $modules = [];

        foreach ($keys as $key) {
            $modules[explode('.', $key)[0]][] = ['key' => $key, 'label' => $this->registry->find($key)?->label() ?? $key];
        }

        ksort($modules);

        return $modules;
    }
}
