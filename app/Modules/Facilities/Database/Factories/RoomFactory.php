<?php

namespace App\Modules\Facilities\Database\Factories;

use App\Modules\Academic\Models\Department;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Aula activa de 30 lugares, sin dueño, en un edificio y con el campus de ese edificio.
 *
 * @extends Factory<Room>
 */
#[UseModel(Room::class)]
class RoomFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'building_id' => Building::factory(),
            'campus_id' => fn (array $attributes): string => Building::query()->findOrFail($attributes['building_id'])->campus_id,
            'code' => fake()->unique()->bothify('S-###'),
            'name' => null,
            'type' => RoomType::Aula,
            'capacity' => 30,
            'department_id' => null,
            'features' => [],
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function ofType(RoomType $type): static
    {
        return $this->state(['type' => $type]);
    }

    public function ownedBy(Department $department): static
    {
        return $this->state(['department_id' => $department->id]);
    }

    public function capacity(int $capacity): static
    {
        return $this->state(['capacity' => $capacity]);
    }
}
