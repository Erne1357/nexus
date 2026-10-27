<?php

namespace App\Modules\Core\Database\Factories;

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\RoleScope;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Usuario activo que ya cambió su contraseña inicial.
 *
 * @extends Factory<User>
 */
#[UseModel(User::class)]
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => fake()->unique()->numerify('########'),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'is_active' => true,
            'must_change_password' => false,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Admin: le basta el rol de spatie, sin alcance.
     */
    public function admin(): static
    {
        return $this->withRole(Role::Admin);
    }

    /**
     * Con el rol de spatie y, salvo admin, un alcance vigente con las columnas de `$scope`
     * (`campus_id`, `department_id`, `career_id`, `valid_from`, `valid_to`). Se encadena para
     * dar varios roles o varios alcances del mismo rol.
     *
     * @param  array<string, mixed>  $scope
     *
     * @throws InvalidArgumentException si `$scope` no llena Role::scopeColumns() del rol
     */
    public function withRole(Role $role, array $scope = []): static
    {
        foreach ($role->scopeColumns() as $column) {
            if (($scope[$column] ?? null) === null) {
                throw new InvalidArgumentException("El alcance de {$role->value} necesita {$column}.");
            }
        }

        return $this->afterCreating(function (User $user) use ($role, $scope): void {
            $user->assignRole(SpatieRole::findOrCreate($role->value));

            if ($role !== Role::Admin) {
                RoleScope::factory()->for($user)->create(['role' => $role, ...$scope]);
            }
        });
    }
}
