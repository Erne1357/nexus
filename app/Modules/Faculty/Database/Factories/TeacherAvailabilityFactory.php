<?php

namespace App\Modules\Faculty\Database\Factories;

use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Enums\AvailabilityKind;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherAvailability;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Bloque no disponible de lunes de 9 a 10 en un periodo nuevo.
 *
 * @extends Factory<TeacherAvailability>
 */
#[UseModel(TeacherAvailability::class)]
class TeacherAvailabilityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'teacher_id' => Teacher::factory(),
            'term_id' => Term::factory(),
            'day' => 1,
            'starts_at' => '09:00',
            'ends_at' => '10:00',
            'kind' => AvailabilityKind::Unavailable,
        ];
    }

    public function unavailable(): static
    {
        return $this->state(['kind' => AvailabilityKind::Unavailable]);
    }

    public function preferred(): static
    {
        return $this->state(['kind' => AvailabilityKind::Preferred]);
    }
}
