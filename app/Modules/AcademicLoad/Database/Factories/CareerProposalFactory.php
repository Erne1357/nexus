<?php

namespace App\Modules\AcademicLoad\Database\Factories;

use App\Modules\Academic\Models\Career;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Propuesta en borrador de una carrera en Presencial y un periodo, sin filas (M6). Presencial la siembra la migración
 * del catálogo de modalidades.
 *
 * @extends Factory<CareerProposal>
 */
#[UseModel(CareerProposal::class)]
class CareerProposalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'term_id' => Term::factory(),
            'modality_id' => fn (): string => Modality::withTrashed()->where('code', 'PRES')->value('id'),
            'career_id' => Career::factory(),
            'eligible_students' => 0,
            'status' => ProposalStatus::Draft,
        ];
    }

    /**
     * La propuesta en otro estado, sin quién la envió.
     */
    public function inStatus(ProposalStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    /**
     * Enviada ahora por un usuario.
     */
    public function submitted(): static
    {
        return $this->state([
            'status' => ProposalStatus::Submitted,
            'submitted_by' => User::factory(),
            'submitted_at' => now(),
        ]);
    }

    /**
     * Enviada y regresada por algún departamento.
     */
    public function returned(): static
    {
        return $this->submitted()->state(['status' => ProposalStatus::Returned]);
    }
}
