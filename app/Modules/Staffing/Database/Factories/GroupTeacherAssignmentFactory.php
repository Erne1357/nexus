<?php

namespace App\Modules\Staffing\Database\Factories;

use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Asignación vigente del primer lugar de un ranking de 5, sin motivo (HC-16 solo lo exige fuera del primer lugar).
 *
 * @extends Factory<GroupTeacherAssignment>
 */
#[UseModel(GroupTeacherAssignment::class)]
class GroupTeacherAssignmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'term_id' => fn (array $attributes): string => Group::query()->findOrFail($attributes['group_id'])->term_id,
            'campus_id' => fn (array $attributes): string => Group::query()->findOrFail($attributes['group_id'])->campus_id,
            'modality_id' => fn (array $attributes): string => Group::query()->findOrFail($attributes['group_id'])->modality_id,
            'teacher_id' => Teacher::factory(),
            'assigned_by' => User::factory(),
            'assigned_at' => now(),
            'ranking_position_at_assignment' => 1,
            'ranking_size_at_assignment' => 5,
            'reason' => null,
            'replaced_at' => null,
            'replaced_by' => null,
        ];
    }

    /**
     * Asignado al tercer lugar de 12, con el motivo que pide HC-16.
     */
    public function outsideTop(): static
    {
        return $this->state([
            'ranking_position_at_assignment' => 3,
            'ranking_size_at_assignment' => 12,
            'reason' => 'Prefiere este grupo y tiene la disponibilidad.',
        ]);
    }

    /**
     * Ya reemplazada por otra asignación.
     */
    public function replaced(): static
    {
        return $this->state(fn (array $attributes): array => [
            'assigned_at' => now()->subDay(),
            'replaced_at' => now(),
            'replaced_by' => User::factory(),
        ]);
    }
}
