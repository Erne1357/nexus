<?php

namespace App\Modules\Core\Rules;

use App\Modules\Core\Authorization\PermissionGrantRules;
use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\PermissionSet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de los roles y de los puestos que comparten las Actions de «Puestos y permisos» (CF-01 a CF-04, CF-S1 y
 * CF-S2). Cada regla rota es una ValidationException sobre su campo, con el mensaje en español.
 *
 * - `name`: recortado, no vacío, máx. 80 y único sin importar mayúsculas entre los roles no borrados (CF-S2).
 * - `description`: opcional, recortada, máx. 240; vacía es nula.
 * - `permissions`: al menos una clave, todas declaradas por algún módulo y ninguna candado (CF-03).
 * - Dar un permiso a un puesto, suelto o dentro de un rol: PermissionGrantRules (CF-S1, CF-03, CF-04).
 */
final class PermissionSetRules
{
    public const int NAME_MAX = 80;

    public const int DESCRIPTION_MAX = 240;

    public function __construct(
        private readonly PermissionRegistry $registry,
        private readonly PermissionGrantRules $grantRules,
    ) {}

    /**
     * @throws ValidationException
     */
    public function normalizeName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Escribe el nombre del rol.']);
        }

        if (mb_strlen($name) > self::NAME_MAX) {
            throw ValidationException::withMessages(['name' => 'El nombre del rol lleva a lo más '.self::NAME_MAX.' caracteres.']);
        }

        return $name;
    }

    /**
     * @throws ValidationException
     */
    public function normalizeDescription(?string $description): ?string
    {
        $description = trim($description ?? '');

        if (mb_strlen($description) > self::DESCRIPTION_MAX) {
            throw ValidationException::withMessages(['description' => 'La descripción lleva a lo más '.self::DESCRIPTION_MAX.' caracteres.']);
        }

        return $description === '' ? null : $description;
    }

    /**
     * Revisa un nombre ya normalizado; `$except` es el rol que lo conserva al editarse.
     *
     * @throws ValidationException
     */
    public function ensureNameIsFree(string $name, ?PermissionSet $except = null): void
    {
        $taken = PermissionSet::query()
            ->whereRaw('lower(name) = lower(?)', [$name])
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();

        if ($taken) {
            throw $this->nameTaken($name);
        }
    }

    /**
     * El error de nombre repetido, también cuando el índice único gana la carrera.
     */
    public function nameTaken(string $name): ValidationException
    {
        return ValidationException::withMessages(['name' => "Ya hay un rol que se llama «{$name}»."]);
    }

    /**
     * CF-S2 y CF-03: las claves de un rol, sin repetir y ordenadas.
     *
     * @param  list<string>  $keys
     * @return list<string>
     *
     * @throws ValidationException
     */
    public function permissionKeys(array $keys, string $field = 'permissions'): array
    {
        $keys = array_values(array_unique(array_map('strval', $keys)));
        sort($keys);

        if ($keys === []) {
            throw ValidationException::withMessages([$field => 'Elige al menos un permiso.']);
        }

        $problems = [];

        foreach ($keys as $key) {
            $permission = $this->registry->find($key);
            $problems[] = $permission === null
                ? "El permiso «{$key}» no existe."
                : $this->grantRules->lockProblem($permission);
        }

        $problems = array_values(array_filter($problems));

        if ($problems !== []) {
            throw ValidationException::withMessages([$field => $problems]);
        }

        return $keys;
    }

    /**
     * CF-S1: el admin no se edita.
     *
     * @throws ValidationException
     */
    public function ensureEditable(Role $role): void
    {
        if (($problem = $this->grantRules->positionProblem($role)) !== null) {
            throw ValidationException::withMessages(['role' => $problem]);
        }
    }

    /**
     * CF-03 y CF-04: que cada clave se pueda dar a cada puesto (lo de fábrica del puesto siempre). Todos los motivos
     * salen juntos sobre `$field`. Las claves que ningún módulo declara no aportan nada y no se revisan.
     *
     * @param  list<Role>  $roles
     * @param  list<string>  $keys
     *
     * @throws ValidationException
     */
    public function ensureGrantable(array $roles, array $keys, string $field): void
    {
        $problems = [];

        foreach ($roles as $role) {
            foreach ($keys as $key) {
                $permission = $this->registry->find($key);

                if ($permission !== null && ($problem = $this->grantRules->grantProblem($role, $permission)) !== null) {
                    $problems[] = $problem;
                }
            }
        }

        if ($problems !== []) {
            throw ValidationException::withMessages([$field => array_values(array_unique($problems))]);
        }
    }

    /**
     * Lo que aporta el rol: lo que el código concede a su puesto si lo sigue (CF-02), o sus filas. Ordenado.
     *
     * @return list<string>
     */
    public function keysOf(PermissionSet $set): array
    {
        if ($set->follows_factory && $set->factory_role !== null) {
            return $this->factoryKeys($set->factory_role);
        }

        return DB::table('permission_set_permissions')->where('permission_set_id', $set->getKey())->orderBy('permission')->pluck('permission')->all();
    }

    /**
     * Lo que el código concede de fábrica al puesto, ordenado.
     *
     * @return list<string>
     */
    public function factoryKeys(Role $role): array
    {
        return $this->registry->matrix()[$role->value] ?? [];
    }

    /**
     * CF-02: las claves de fábrica del puesto del rol que el código agregó después de que el rol dejó de seguirlo, y
     * que el rol no tiene. Vacío si el rol no es de fábrica o lo sigue.
     *
     * @return list<string>
     */
    public function newFactoryKeys(PermissionSet $set): array
    {
        if ($set->factory_role === null || $set->follows_factory) {
            return [];
        }

        return array_values(array_diff($this->factoryKeys($set->factory_role), $set->factory_baseline ?? [], $this->keysOf($set)));
    }

    /**
     * Los puestos que tienen el rol, en el orden del enum.
     *
     * @return list<Role>
     */
    public function positionsOf(PermissionSet $set): array
    {
        $values = DB::table('position_permission_sets')->where('permission_set_id', $set->getKey())->pluck('role')->all();

        return array_values(array_filter(Role::cases(), fn (Role $role): bool => in_array($role->value, $values, true)));
    }

    /**
     * Lo que tiene el puesto, para la bitácora: los nombres de sus roles y sus permisos sueltos, ordenados.
     *
     * @return array{permission_sets: list<string>, permissions: list<string>}
     */
    public function positionState(Role $role): array
    {
        return [
            'permission_sets' => DB::table('position_permission_sets')
                ->join('permission_sets', 'permission_sets.id', '=', 'position_permission_sets.permission_set_id')
                ->where('position_permission_sets.role', $role->value)
                ->orderBy('permission_sets.name')
                ->pluck('permission_sets.name')
                ->all(),
            'permissions' => DB::table('position_permissions')->where('role', $role->value)->orderBy('permission')->pluck('permission')->all(),
        ];
    }

    /**
     * Cambia las filas del rol por `$keys`.
     *
     * @param  list<string>  $keys
     */
    public function replaceKeys(PermissionSet $set, array $keys): void
    {
        DB::table('permission_set_permissions')->where('permission_set_id', $set->getKey())->delete();
        DB::table('permission_set_permissions')->insert(array_map(fn (string $key): array => ['permission_set_id' => $set->getKey(), 'permission' => $key], $keys));
    }
}
