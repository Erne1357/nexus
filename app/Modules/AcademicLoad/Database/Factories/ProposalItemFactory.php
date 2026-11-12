<?php

namespace App\Modules\AcademicLoad\Database\Factories;

use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fila de primer semestre con 30 elegibles, 30 esperados y un grupo solicitado, con un plan de la carrera de la
 * propuesta (como las arma StartCareerProposal).
 *
 * @extends Factory<ProposalItem>
 */
#[UseModel(ProposalItem::class)]
class ProposalItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'career_proposal_id' => CareerProposal::factory(),
            'campus_id' => fn (array $attributes): string => CareerProposal::query()->findOrFail($attributes['career_proposal_id'])->campus_id,
            'subject_id' => Subject::factory(),
            'study_plan_id' => fn (array $attributes): string => StudyPlan::factory()->create([
                'career_id' => CareerProposal::query()->findOrFail($attributes['career_proposal_id'])->career_id,
            ])->id,
            'semester' => 1,
            'eligible_students' => 30,
            'expected_students' => 30,
            'requested_groups' => 1,
            'notes' => null,
        ];
    }

    /**
     * Una materia que no se pide: cero grupos.
     */
    public function withoutGroups(): static
    {
        return $this->state(['requested_groups' => 0]);
    }
}
