<?php

namespace App\Modules\Core\Database\Factories;

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Nombramiento de docente, de todo el Tec (sin campus ni unidad), vigente desde hace un mes y sin fecha de fin. Para un
 * usuario con nombramiento de otro puesto usa UserFactory::withRole().
 *
 * @extends Factory<RoleAssignment>
 */
#[UseModel(RoleAssignment::class)]
class RoleAssignmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'role' => Role::Docente,
            'campus_id' => null,
            'org_unit_id' => null,
            'career_id' => null,
            'valid_from' => today()->subMonth()->toDateString(),
            'valid_to' => null,
        ];
    }
}
