<?php

namespace App\Modules\Scheduling\Database\Factories;

use App\Modules\Core\Models\Campus;
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
        return [
            'group_id' => Group::factory(),
            'rule' => 'HC-02',
            'severity' => ConflictSeverity::Hard,
            'subject_type' => 'group',
            'related' => [],
            'message' => 'El salón ya está ocupado a esa hora.',
            'resolved_at' => null,
        ];
    }

    /**
     * Lo que no se indicó sale del grupo, leído una sola vez: su periodo, campus, departamento, carrera dueña y el
     * grupo como sujeto; la modalidad, del campus del choque, como la llena SyncScheduleConflicts.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (ScheduleConflict $conflict): void {
            $group = Group::query()->findOrFail($conflict->group_id);

            $conflict->term_id ??= $group->term_id;
            $conflict->campus_id ??= $group->campus_id;
            $conflict->modality_id ??= Campus::withTrashed()->whereKey($conflict->campus_id)->value('modality_id');
            $conflict->department_id ??= $group->department_id;
            $conflict->career_id ??= $group->owner_career_id;
            $conflict->subject_id ??= $group->id;
        });
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
