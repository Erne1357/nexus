<?php

namespace App\Modules\Scheduling\Database\Factories;

use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\ScheduleConflict;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Choque duro HC-02 de un grupo, vigente.
 *
 * @extends Factory<ScheduleConflict>
 */
#[UseModel(ScheduleConflict::class)]
class ScheduleConflictFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $group = Group::factory();

        return [
            'group_id' => $group,
            'term_id' => fn (array $attributes): string => Group::query()->findOrFail($attributes['group_id'])->term_id,
            'campus_id' => fn (array $attributes): string => Group::query()->findOrFail($attributes['group_id'])->campus_id,
            'department_id' => fn (array $attributes): string => Group::query()->findOrFail($attributes['group_id'])->department_id,
            'career_id' => fn (array $attributes): string => Group::query()->findOrFail($attributes['group_id'])->owner_career_id,
            'rule' => 'HC-02',
            'severity' => ConflictSeverity::Hard,
            'subject_type' => 'group',
            'subject_id' => fn (array $attributes): string => $attributes['group_id'],
            'related' => [],
            'message' => 'El salón ya está ocupado a esa hora.',
            'resolved_at' => null,
        ];
    }

    /**
     * Choque de un grupo: su periodo, campus, departamento, carrera dueña y el grupo como sujeto.
     */
    public function forGroup(Group $group): static
    {
        return $this->state([
            'group_id' => $group->id,
            'term_id' => $group->term_id,
            'campus_id' => $group->campus_id,
            'department_id' => $group->department_id,
            'career_id' => $group->owner_career_id,
            'subject_type' => 'group',
            'subject_id' => $group->id,
        ]);
    }

    public function soft(string $rule = 'SC-01'): static
    {
        return $this->state(['rule' => $rule, 'severity' => ConflictSeverity::Soft]);
    }

    public function resolved(): static
    {
        return $this->state(['resolved_at' => now()]);
    }
}
