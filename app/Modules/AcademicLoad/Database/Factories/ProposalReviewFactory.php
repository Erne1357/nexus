<?php

namespace App\Modules\AcademicLoad\Database\Factories;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Parte pendiente de un departamento en una propuesta de carrera.
 *
 * @extends Factory<ProposalReview>
 */
#[UseModel(ProposalReview::class)]
class ProposalReviewFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'career_proposal_id' => CareerProposal::factory(),
            'department_id' => Department::factory(),
            'status' => ProposalReviewStatus::Pending,
            'comment' => null,
        ];
    }

    /**
     * Aceptada ahora por un usuario del departamento.
     */
    public function accepted(): static
    {
        return $this->state([
            'status' => ProposalReviewStatus::Accepted,
            'decided_by' => User::factory(),
            'decided_at' => now(),
        ]);
    }

    /**
     * Regresada ahora por un usuario del departamento, con su comentario.
     */
    public function returned(string $comment = 'Revisa cuántos grupos pides.'): static
    {
        return $this->state([
            'status' => ProposalReviewStatus::Returned,
            'comment' => $comment,
            'decided_by' => User::factory(),
            'decided_at' => now(),
        ]);
    }
}
