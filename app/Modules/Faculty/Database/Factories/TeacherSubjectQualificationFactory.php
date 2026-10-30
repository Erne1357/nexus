<?php

namespace App\Modules\Faculty\Database\Factories;

use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Enums\QualificationLevel;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherSubjectQualification;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Perfil apto, declarado por un usuario nuevo.
 *
 * @extends Factory<TeacherSubjectQualification>
 */
#[UseModel(TeacherSubjectQualification::class)]
class TeacherSubjectQualificationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'teacher_id' => Teacher::factory(),
            'subject_id' => Subject::factory(),
            'level' => QualificationLevel::Apto,
            'qualified_by' => User::factory(),
            'notes' => null,
        ];
    }

    public function apto(): static
    {
        return $this->state(['level' => QualificationLevel::Apto]);
    }

    public function preferente(): static
    {
        return $this->state(['level' => QualificationLevel::Preferente]);
    }
}
