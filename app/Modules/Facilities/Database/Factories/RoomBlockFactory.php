<?php

namespace App\Modules\Facilities\Database\Factories;

use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Models\RoomBlock;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Bloqueo de mantenimiento el lunes de 08:00 a 10:00, para todos los periodos.
 *
 * @extends Factory<RoomBlock>
 */
#[UseModel(RoomBlock::class)]
class RoomBlockFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'term_id' => null,
            'day' => 1,
            'starts_at' => '08:00',
            'ends_at' => '10:00',
            'reason' => 'Mantenimiento',
        ];
    }
}
