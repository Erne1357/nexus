<?php

namespace App\Modules\Faculty\Database\Factories;

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Campus;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Adscripción principal de un docente nuevo a un departamento y campus nuevos.
 *
 * @extends Factory<TeacherDepartment>
 */
#[UseModel(TeacherDepartment::class)]
class TeacherDepartmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'teacher_id' => Teacher::factory(),
            'department_id' => Department::factory(),
            'campus_id' => Campus::factory(),
            'is_primary' => true,
        ];
    }
}
