<?php

namespace App\Modules\Scheduling\Database\Factories;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Grupo planeado de primer semestre, de 35 lugares y sin docente, con el departamento de su materia, un plan de su
 * carrera dueña y la modalidad del catálogo de su campus. Sin cupos: `withQuota()` le da el de su carrera dueña.
 *
 * @extends Factory<Group>
 */
#[UseModel(Group::class)]
class GroupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'term_id' => Term::factory(),
            'campus_id' => Campus::factory(),
            'modality_id' => fn (array $attributes): ?string => Campus::withTrashed()->whereKey($attributes['campus_id'])->value('modality_id'),
            'subject_id' => Subject::factory(),
            'department_id' => fn (array $attributes): string => Subject::query()->findOrFail($attributes['subject_id'])->department_id,
            'owner_career_id' => Career::factory(),
            'study_plan_id' => fn (array $attributes): string => StudyPlan::factory()->create(['career_id' => $attributes['owner_career_id']])->id,
            'semester' => 1,
            'code' => strtoupper(fake()->unique()->bothify('??##')),
            'teacher_id' => null,
            'capacity' => 35,
            'status' => GroupStatus::Planned,
            'proposal_item_id' => null,
            'notes' => null,
        ];
    }

    /**
     * Con el cupo de su carrera dueña: toda la capacidad o los lugares indicados.
     */
    public function withQuota(?int $seats = null): static
    {
        return $this->afterCreating(fn (Group $group) => GroupQuota::query()->create([
            'group_id' => $group->id,
            'career_id' => $group->owner_career_id,
            'max_seats' => $seats ?? $group->capacity,
            'is_owner' => true,
        ]));
    }

    /**
     * El grupo que se crea desde una fila de la propuesta: su materia, plan, semestre y campus (M8), y el periodo,
     * modalidad y carrera de la propuesta.
     */
    public function forProposalItem(ProposalItem $item): static
    {
        $proposal = $item->proposal;

        return $this->state([
            'proposal_item_id' => $item->id,
            'term_id' => $proposal->term_id,
            'campus_id' => $item->campus_id,
            'modality_id' => $proposal->modality_id,
            'subject_id' => $item->subject_id,
            'owner_career_id' => $proposal->career_id,
            'study_plan_id' => $item->study_plan_id,
            'semester' => $item->semester,
        ]);
    }

    public function withTeacher(?Teacher $teacher = null): static
    {
        return $this->state(['teacher_id' => $teacher?->id ?? Teacher::factory()]);
    }

    public function confirmed(): static
    {
        return $this->state(['status' => GroupStatus::Confirmed]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => GroupStatus::Cancelled]);
    }
}
