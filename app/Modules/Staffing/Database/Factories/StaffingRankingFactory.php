<?php

namespace App\Modules\Staffing\Database\Factories;

use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Models\StaffingRanking;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Primer lugar de un grupo nuevo, con el periodo y la modalidad del grupo (el campus se lee del grupo).
 *
 * @extends Factory<StaffingRanking>
 */
#[UseModel(StaffingRanking::class)]
class StaffingRankingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'term_id' => fn (array $attributes): string => Group::query()->findOrFail($attributes['group_id'])->term_id,
            'modality_id' => fn (array $attributes): string => Group::query()->findOrFail($attributes['group_id'])->modality_id,
            'teacher_id' => Teacher::factory(),
            'rank' => 1,
            'score' => 10,
            'breakdown' => ['base_hours' => 0, 'history_count' => 0, 'qualification' => 0, 'evaluation' => 0, 'remaining_hours' => 0],
            'computed_at' => now(),
        ];
    }
}
