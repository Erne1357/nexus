<?php

namespace App\Modules\Core\Database\Factories;

use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\PermissionSet;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * Un rol propio (sin puesto de fábrica), activo, sin permisos ni puestos: withPermissions() y assignedTo() los
 * agregan.
 *
 * @extends Factory<PermissionSet>
 */
#[UseModel(PermissionSet::class)]
class PermissionSetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Rol '.fake()->unique()->numerify('#####'),
            'description' => null,
            'factory_role' => null,
            'follows_factory' => false,
            'factory_baseline' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * Con estas claves en `permission_set_permissions`.
     *
     * @param  list<Permission|string>  $permissions
     */
    public function withPermissions(array $permissions): static
    {
        return $this->afterCreating(fn (PermissionSet $set) => DB::table('permission_set_permissions')->insert(array_map(
            fn (Permission|string $permission): array => ['permission_set_id' => $set->id, 'permission' => $permission instanceof Permission ? $permission->value : $permission],
            $permissions,
        )));
    }

    /**
     * Dado a estos puestos.
     */
    public function assignedTo(Role ...$roles): static
    {
        return $this->afterCreating(fn (PermissionSet $set) => DB::table('position_permission_sets')->insert(array_map(
            fn (Role $role): array => ['role' => $role->value, 'permission_set_id' => $set->id],
            $roles,
        )));
    }
}
