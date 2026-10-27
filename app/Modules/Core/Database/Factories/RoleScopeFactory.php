<?php

namespace App\Modules\Core\Database\Factories;

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleScope;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Alcance de docente en un campus nuevo, vigente desde hace un mes y sin fecha de fin. No asigna
 * el rol de spatie: para un usuario con rol y alcance usa UserFactory::withRole().
 *
 * @extends Factory<RoleScope>
 */
#[UseModel(RoleScope::class)]
class RoleScopeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'role' => Role::Docente,
            'campus_id' => Campus::factory(),
            'department_id' => null,
            'career_id' => null,
            'valid_from' => today()->subMonth()->toDateString(),
            'valid_to' => null,
        ];
    }
}
