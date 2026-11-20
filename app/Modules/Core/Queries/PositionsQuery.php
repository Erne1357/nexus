<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Authorization\GrantTable;
use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\Authorization\PermissionGrantRules;
use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Rules\PermissionSetRules;
use Illuminate\Support\Facades\DB;

/**
 * Los puestos para «Puestos y permisos» (CF-01, CF-S1): en el orden del enum, cada uno con a quién cubre, sus roles, sus
 * permisos sueltos, lo que puede hoy (lo de GrantTable) con el origen de cada permiso, y cuántas personas lo ocupan hoy.
 * El admin sale de solo lectura: tiene todo lo declarado menos sus excepciones, que se listan.
 *
 * El origen va dos veces: como texto (`origins`) y como dato (`sources`) para la pantalla: `factory` es el rol de
 * fábrica del mismo puesto, `role` cualquier otro rol (también el de fábrica de otro puesto), `loose` el permiso suelto
 * y `admin` lo que el admin tiene por ser admin.
 */
final class PositionsQuery
{
    public const string ADMIN_ORIGIN = 'Todo lo declarado';

    public const string LOOSE_ORIGIN = 'Permiso suelto';

    public function __construct(
        private readonly PermissionRegistry $registry,
        private readonly GrantTable $grants,
        private readonly PermissionGrantRules $grantRules,
        private readonly PermissionSetRules $rules,
    ) {}

    /**
     * Un rol activo aporta con el origen «Rol «<nombre>»»; uno desactivado sale en `permission_sets` pero no aporta.
     * Los borrados no salen. `$peopleByRoleSet` es peopleByRoleSet(), si ya se leyó.
     *
     * @param  array<string, int>|null  $peopleByRoleSet
     * @return list<array{
     *     role: Role,
     *     label: string,
     *     scope: string,
     *     locked: string|null,
     *     holders: int,
     *     permission_sets: list<array{id: string, name: string, is_active: bool, is_factory: bool, factory_role: Role|null, follows_factory: bool}>,
     *     permissions: list<string>,
     *     effective: list<array{key: string, label: string, module: string, origins: list<string>, sources: list<array{kind: 'admin'|'factory'|'role'|'loose', name: string|null}>}>,
     *     admin_denies: list<string>,
     * }>
     */
    public function all(?array $peopleByRoleSet = null): array
    {
        $holders = $this->holdersByRole($peopleByRoleSet);
        $sets = DB::table('position_permission_sets')
            ->join('permission_sets', 'permission_sets.id', '=', 'position_permission_sets.permission_set_id')
            ->whereNull('permission_sets.deleted_at')
            ->orderBy('permission_sets.name')
            ->get(['position_permission_sets.role', 'permission_sets.id', 'permission_sets.name', 'permission_sets.is_active', 'permission_sets.factory_role', 'permission_sets.follows_factory']);
        $own = DB::table('permission_set_permissions')->whereIn('permission_set_id', $sets->pluck('id')->unique()->values()->all())->get()->groupBy('permission_set_id');
        $loose = DB::table('position_permissions')->orderBy('permission')->get()->groupBy('role');
        $positions = [];

        foreach (Role::cases() as $role) {
            $locked = $this->grantRules->positionProblem($role);
            $roleSets = $sets->where('role', $role->value)->values();
            $looseKeys = $loose->get($role->value)?->pluck('permission')->all() ?? [];
            $origins = [];
            $sources = [];

            foreach ($roleSets->where('is_active', true) as $set) {
                $keys = $this->rules->contributedKeys((bool) $set->follows_factory, $set->factory_role, $own->get($set->id)?->pluck('permission')->all() ?? []);

                foreach ($keys as $key) {
                    $origins[$key][] = "Rol «{$set->name}»";
                    $sources[$key][] = ['kind' => $set->factory_role === $role->value ? 'factory' : 'role', 'name' => $set->name];
                }
            }

            foreach ($looseKeys as $key) {
                $origins[$key][] = self::LOOSE_ORIGIN;
                $sources[$key][] = ['kind' => 'loose', 'name' => null];
            }

            $effective = array_values(array_filter($this->registry->permissions(), fn (Permission $permission): bool => $this->grants->grants($role, $permission)));

            $positions[] = [
                'role' => $role,
                'label' => $role->listLabel(),
                'scope' => $this->scopeOf($role),
                'locked' => $locked,
                'holders' => $holders[$role->value] ?? 0,
                'permission_sets' => $roleSets->map(fn (object $set): array => [
                    'id' => $set->id,
                    'name' => $set->name,
                    'is_active' => (bool) $set->is_active,
                    'is_factory' => $set->factory_role !== null,
                    'factory_role' => $set->factory_role === null ? null : Role::tryFrom($set->factory_role),
                    'follows_factory' => (bool) $set->follows_factory,
                ])->all(),
                'permissions' => $looseKeys,
                'effective' => array_map(fn (Permission $permission): array => [
                    'key' => (string) $permission->value,
                    'label' => $permission->label(),
                    'module' => explode('.', (string) $permission->value)[0],
                    'origins' => $locked !== null ? [self::ADMIN_ORIGIN] : ($origins[$permission->value] ?? []),
                    'sources' => $locked !== null ? [['kind' => 'admin', 'name' => null]] : ($sources[$permission->value] ?? []),
                ], $effective),
                'admin_denies' => $locked !== null ? $this->registry->adminDenials() : [],
            ];
        }

        return $positions;
    }

    /**
     * A quién cubre el puesto, en una línea: lo que acota (Role::boundedDimensions(), CF-04) dicho como lo nombra su
     * nombramiento.
     */
    private function scopeOf(Role $role): string
    {
        return match ($role) {
            Role::Admin => 'Sin alcance: vale en todo Nexus',
            Role::Subdireccion => 'Su unidad; todo el Tec en campus y modalidad',
            Role::Directivo => 'Su unidad, solo para su credencial; lee en todo el Tec',
            Role::JefeDepartamento => 'Su departamento; todo el Tec en campus y modalidad',
            Role::JefeProyectoDocente => 'Su departamento y su modalidad; el campus, si lo lleva',
            Role::Coordinador => 'Su carrera y su modalidad; el campus, si lo lleva',
            Role::Docente, Role::Alumno => 'Sin alcance: solo permisos personales',
        };
    }

    /**
     * Cuántas personas ocupan hoy cada puesto: usuarios activos con un nombramiento vigente de ese puesto.
     *
     * @param  array<string, int>|null  $peopleByRoleSet  peopleByRoleSet(), si ya se leyó
     * @return array<string, int> por `Role->value`
     */
    public function holdersByRole(?array $peopleByRoleSet = null): array
    {
        $byRole = [];

        foreach ($peopleByRoleSet ?? $this->peopleByRoleSet() as $roles => $people) {
            foreach (explode(',', $roles) as $role) {
                $byRole[$role] = ($byRole[$role] ?? 0) + $people;
            }
        }

        return $byRole;
    }

    /**
     * Cuántas personas tienen hoy cada combinación de puestos (los valores de `Role`, ordenados y separados por coma),
     * en una consulta: con eso se cuentan personas distintas por puesto y por permiso sin cargar a cada usuario.
     *
     * @return array<string, int>
     */
    public function peopleByRoleSet(): array
    {
        $perUser = RoleAssignment::query()
            ->current()
            ->join('users', 'users.id', '=', 'role_assignments.user_id')
            ->where('users.is_active', true)
            ->groupBy('role_assignments.user_id')
            ->selectRaw("string_agg(distinct role_assignments.role, ',' order by role_assignments.role) as roles");

        return DB::query()
            ->fromSub($perUser, 'people')
            ->groupBy('roles')
            ->selectRaw('roles, count(*) as people')
            ->pluck('people', 'roles')
            ->map(fn (mixed $people): int => (int) $people)
            ->all();
    }
}
