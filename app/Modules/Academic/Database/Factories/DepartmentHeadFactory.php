<?php

namespace App\Modules\Academic\Database\Factories;

use App\Modules\Academic\Enums\DepartmentHeadKind;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\DepartmentHead;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Jefe vigente de un departamento, de todo el Tec (sin campus) y sin fecha de fin.
 *
 * @extends Factory<DepartmentHead>
 */
#[UseModel(DepartmentHead::class)]
class DepartmentHeadFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'department_id' => Department::factory(),
            'campus_id' => null,
            'user_id' => User::factory(),
            'kind' => DepartmentHeadKind::Jefe,
            'valid_from' => today()->subMonth()->toDateString(),
            'valid_to' => null,
        ];
    }

    /**
     * Jefe de proyecto docente, de un campus (el de `for()` o uno nuevo): puede haber varios vigentes a la vez.
     */
    public function projectLead(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => DepartmentHeadKind::JefeProyectoDocente,
            'campus_id' => $attributes['campus_id'] ?? Campus::factory(),
        ]);
    }
}
