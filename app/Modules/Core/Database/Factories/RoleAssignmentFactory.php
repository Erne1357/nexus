<?php

namespace App\Modules\Core\Database\Factories;

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Nombramiento de docente, de todo el Tec (sin campus ni unidad), vigente desde hace un mes y sin fecha de fin. Para un
 * usuario con nombramiento de otro puesto usa UserFactory::withRole(). Un puesto que pide modalidad (coordinador y jefe
 * de proyecto docente, AZ-S17) va en Presencial (`PRES`, la siembra la migración del catálogo) si no se le da otra.
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
            'modality_id' => function (array $attributes): ?string {
                $role = $attributes['role'] instanceof Role ? $attributes['role'] : Role::from($attributes['role']);

                return in_array('modality_id', $role->scopeColumns(), true)
                    ? Modality::withTrashed()->where('code', 'PRES')->value('id')
                    : null;
            },
            'valid_from' => today()->subMonth()->toDateString(),
            'valid_to' => null,
        ];
    }
}
