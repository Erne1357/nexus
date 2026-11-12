<?php

namespace App\Modules\Core\Database\Factories;

use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Enums\PasswordOrigin;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

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
            'password_origin' => PasswordOrigin::User,
            'is_active' => true,
            'must_change_password' => false,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Admin: un nombramiento `admin` sin alcance.
     */
    public function admin(): static
    {
        return $this->withRole(Role::Admin);
    }

    /**
     * Con un nombramiento vigente con las columnas de `$scope` (`campus_id`, `org_unit_id`,
     * `career_id`, `modality_id`, `valid_from`, `valid_to`); el de admin, sin alcance. `department_id` es otro nombre de
     * `org_unit_id`: el id de un departamento es el de su unidad. Sin `org_unit_id`, la Subdirección va sobre la unidad
     * Subdirección Académica (AZ-S3). Sin `campus_id`, el nombramiento es de todo el Tec (en el coordinador y el jefe
     * de proyecto docente, de todos los campus de su modalidad). Sin `modality_id`, el coordinador y el jefe de proyecto
     * docente van en Presencial (RoleAssignmentFactory). Se encadena para dar varios puestos o varios nombramientos del
     * mismo puesto.
     *
     * @param  array<string, mixed>  $scope
     *
     * @throws InvalidArgumentException si `$scope` no llena Role::scopeColumns() del puesto
     */
    public function withRole(Role $role, array $scope = []): static
    {
        if (array_key_exists('department_id', $scope)) {
            $scope['org_unit_id'] ??= $scope['department_id'];
            unset($scope['department_id']);
        }

        $academicDefault = $role === Role::Subdireccion && ($scope['org_unit_id'] ?? null) === null;

        foreach ($role->scopeColumns() as $column) {
            if ($column === 'modality_id') {
                continue;
            }

            if (($scope[$column] ?? null) === null && ! ($academicDefault && $column === 'org_unit_id')) {
                throw new InvalidArgumentException("El alcance de {$role->value} necesita {$column}.");
            }
        }

        return $this->afterCreating(function (User $user) use ($role, $scope, $academicDefault): void {
            if ($academicDefault) {
                $scope['org_unit_id'] = app(OrgTree::class)->idOf(OrgTree::ACADEMIC)
                    ?? throw new RuntimeException('Falta la unidad '.OrgTree::ACADEMIC.' para el nombramiento de Subdirección.');
            }

            RoleAssignment::factory()->for($user)->create(['role' => $role, ...$scope]);
        });
    }
}
