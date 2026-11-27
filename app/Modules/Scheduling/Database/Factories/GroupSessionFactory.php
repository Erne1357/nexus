<?php

namespace App\Modules\Scheduling\Database\Factories;

use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Sesión de teoría el lunes de 7:00 a 9:00, sin salón.
 *
 * @extends Factory<GroupSession>
 */
#[UseModel(GroupSession::class)]
class GroupSessionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'day' => 1,
            'starts_at' => '07:00',
            'ends_at' => '09:00',
            'room_id' => null,
            'kind' => SessionKind::Teoria,
        ];
    }

    /**
     * El día (1 = lunes a 6 = sábado) y las horas, en `HH:MM`.
     */
    public function on(int $day, string $startsAt, string $endsAt): static
    {
        return $this->state(['day' => $day, 'starts_at' => $startsAt, 'ends_at' => $endsAt]);
    }

    public function inRoom(Room $room): static
    {
        return $this->state(['room_id' => $room->id]);
    }

    public function practica(): static
    {
        return $this->state(['kind' => SessionKind::Practica]);
    }

    public function virtual(): static
    {
        return $this->state(['kind' => SessionKind::Virtual, 'room_id' => null]);
    }
}
