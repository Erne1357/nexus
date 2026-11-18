<?php

namespace App\Modules\AcademicLoad\Database\Factories;

use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalPackage;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Paquete «P1» sin materias ni columnas en una propuesta nueva.
 *
 * @extends Factory<ProposalPackage>
 */
#[UseModel(ProposalPackage::class)]
class ProposalPackageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'career_proposal_id' => CareerProposal::factory(),
            'code' => 'P1',
            'name' => null,
            'position' => 1,
        ];
    }
}
