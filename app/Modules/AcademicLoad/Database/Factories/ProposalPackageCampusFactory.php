<?php

namespace App\Modules\AcademicLoad\Database\Factories;

use App\Modules\AcademicLoad\Models\ProposalPackage;
use App\Modules\AcademicLoad\Models\ProposalPackageCampus;
use App\Modules\Core\Models\Campus;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * La columna de un campus de un paquete: cero paquetes, sin sugerido y sin tocar.
 *
 * @extends Factory<ProposalPackageCampus>
 */
#[UseModel(ProposalPackageCampus::class)]
class ProposalPackageCampusFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'proposal_package_id' => ProposalPackage::factory(),
            'campus_id' => Campus::factory(),
            'suggested_count' => null,
            'count' => 0,
        ];
    }
}
