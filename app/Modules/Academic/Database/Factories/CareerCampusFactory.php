<?php

namespace App\Modules\Academic\Database\Factories;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Core\Models\Campus;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Una carrera que se ofrece en un campus.
 *
 * @extends Factory<CareerCampus>
 */
#[UseModel(CareerCampus::class)]
class CareerCampusFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'career_id' => Career::factory(),
            'campus_id' => Campus::factory(),
            'is_active' => true,
        ];
    }

    /**
     * La carrera se dejó de ofrecer en el campus.
     */
    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
