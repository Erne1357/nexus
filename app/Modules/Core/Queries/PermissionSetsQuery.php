<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Authorization\PermissionGrantRules;
use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\PermissionSet;
use App\Modules\Core\Rules\PermissionSetRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Los roles para «Puestos y permisos» (CF-01, CF-02, CF-S2): los no borrados, primero los de fábrica en el orden de
 * sus puestos y luego los propios por nombre, cada uno con los puestos que lo tienen, sus permisos agrupados por
 * módulo y, si es de fábrica y ya no sigue al código, los permisos nuevos de fábrica que se pueden aplicar.
 *
 * Para la pantalla, además: la tabla de roles con su búsqueda y su tipo (forTable()), por qué un rol no se le da a un
 * puesto (assignProblems()) y qué no se le puede agregar a un rol en su editor (addProblems()). Los motivos son los de
 * PermissionGrantRules, los mismos que da la Action al bloquear (CF-03, CF-04, CF-S2).
 */
final class PermissionSetsQuery
{
    public function __construct(
        private readonly PermissionRegistry $registry,
        private readonly PermissionSetRules $rules,
        private readonly PermissionGrantRules $grantRules,
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
                $keys = $this->rules->contributedKeys($set->follows_factory, $set->factory_role?->value, $own->get($set->id)?->pluck('permission')->all() ?? []);
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
                    'new_factory' => $this->rules->newFactoryKeys($set, $keys),
                ];
            })
            ->all();
    }

    /**
     * Los roles de la tabla de «Roles»: los de all() cuyo nombre contiene `$search` (sin importar mayúsculas ni
     * acentos), y solo los de fábrica (`factory`) o solo los propios (`own`); cualquier otro tipo, todos. `$sets` es
     * all(), si ya se leyó.
     *
     * @param  list<array<string, mixed>>|null  $sets
     * @return list<array<string, mixed>>
     */
    public function forTable(string $search, string $kind, ?array $sets = null): array
    {
        $needle = self::folded($search);

        return array_values(array_filter($sets ?? $this->all(), fn (array $set): bool => ($needle === '' || str_contains(self::folded($set['name']), $needle))
            && match ($kind) {
                'factory' => $set['factory_role'] !== null,
                'own' => $set['factory_role'] === null,
                default => true,
            }));
    }

    /**
     * CF-S1, CF-03 y CF-04: por qué cada rol no se le puede dar al puesto, con todos sus motivos (vacío si se puede),
     * como AssignPermissionSet. `$sets` es all(), si ya se leyó.
     *
     * @param  list<array<string, mixed>>|null  $sets
     * @return array<string, list<string>> por id del rol
     */
    public function assignProblems(Role $role, ?array $sets = null): array
    {
        $problems = [];

        foreach ($sets ?? $this->all() as $set) {
            $found = [];

            if (($problem = $this->grantRules->positionProblem($role)) !== null) {
                $found[] = $problem;
            } else {
                foreach ($this->keysIn($set) as $key) {
                    $permission = $this->registry->forKey($key);

                    if ($permission !== null && ($problem = $this->grantRules->grantProblem($role, $permission)) !== null) {
                        $found[] = $problem;
                    }
                }
            }

            $problems[$set['id']] = array_values(array_unique($found));
        }

        return $problems;
    }

    /**
     * CF-03 y CF-S2: lo que no se le puede agregar al rol con id `$setId` en su editor, con su motivo: los candados, y
     * lo que algún puesto que ya tiene el rol no puede recibir (CF-04), como UpdatePermissionSet. Lo que el rol ya trae
     * no sale: se puede dejar o quitar. Con null, un rol nuevo: solo los candados. `$sets` es all(), si ya se leyó.
     *
     * @param  list<array<string, mixed>>|null  $sets
     * @return array<string, string> por clave
     */
    public function addProblems(?string $setId, ?array $sets = null): array
    {
        $set = $setId === null ? null : collect($sets ?? $this->all())->firstWhere('id', $setId);
        $current = $set === null ? [] : $this->keysIn($set);
        $problems = [];

        foreach ($this->registry->permissions() as $permission) {
            $key = (string) $permission->value;

            if (in_array($key, $current, true)) {
                continue;
            }

            $problem = $this->grantRules->lockProblem($permission);

            foreach ($set['positions'] ?? [] as $role) {
                $problem ??= $this->grantRules->grantProblem($role, $permission);
            }

            if ($problem !== null) {
                $problems[$key] = $problem;
            }
        }

        return $problems;
    }

    /**
     * El rol no borrado con ese id, para editarlo; 404 si no existe o el id no es un uuid.
     */
    public function findOrFail(string $id): PermissionSet
    {
        return KeyLookup::findOrFail(PermissionSet::query(), $id);
    }

    /**
     * Las claves que aporta un renglón de all().
     *
     * @param  array<string, mixed>  $set
     * @return list<string>
     */
    private function keysIn(array $set): array
    {
        return array_column(array_merge(...array_values($set['permissions'])), 'key');
    }

    /**
     * En minúsculas y sin acentos, para buscar.
     */
    private static function folded(string $text): string
    {
        return Str::lower(Str::ascii(trim($text)));
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
            $modules[explode('.', $key)[0]][] = ['key' => $key, 'label' => $this->registry->forKey($key)?->label() ?? $key];
        }

        ksort($modules);

        return $modules;
    }
}
