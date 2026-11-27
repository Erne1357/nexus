<?php

namespace App\Modules\Faculty\Database\Factories;

use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Faculty\Enums\HistorySource;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherSubjectHistory;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Un grupo impartido, importado del SII, en un periodo de Ene-Jun.
 *
 * @extends Factory<TeacherSubjectHistory>
 */
#[UseModel(TeacherSubjectHistory::class)]
class TeacherSubjectHistoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'teacher_id' => Teacher::factory(),
            'subject_id' => Subject::factory(),
            'term_code' => '20241',
            'campus_id' => Campus::factory(),
            'group_code' => fake()->unique()->bothify('##??'),
            'source' => HistorySource::Import,
        ];
    }

    public function fromNexus(): static
    {
        return $this->state(['source' => HistorySource::Nexus]);
    }
}
