<?php

namespace App\Modules\AcademicLoad\Database\Factories;

use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalIntake;
use App\Modules\Core\Models\Campus;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * El nuevo ingreso de un campus: cero esperados, sin sugerido y sin tocar.
 *
 * @extends Factory<ProposalIntake>
 */
#[UseModel(ProposalIntake::class)]
class ProposalIntakeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'career_proposal_id' => CareerProposal::factory(),
            'campus_id' => Campus::factory(),
            'suggested_new_students' => null,
            'new_students' => 0,
        ];
    }
}
