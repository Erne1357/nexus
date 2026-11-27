<?php

namespace App\Modules\Facilities\Database\Factories;

use App\Modules\Academic\Models\Subject;
use App\Modules\Facilities\Enums\RoomDefaultSource;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Models\SubjectRoomDefault;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Salón por defecto manual de prioridad 1, en el campus del salón.
 *
 * @extends Factory<SubjectRoomDefault>
 */
#[UseModel(SubjectRoomDefault::class)]
class SubjectRoomDefaultFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject_id' => Subject::factory(),
            'room_id' => Room::factory(),
            'campus_id' => fn (array $attributes): string => Room::query()->findOrFail($attributes['room_id'])->campus_id,
            'priority' => 1,
            'source' => RoomDefaultSource::Manual,
        ];
    }
}
