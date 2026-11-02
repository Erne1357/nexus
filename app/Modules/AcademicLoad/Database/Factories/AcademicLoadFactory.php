<?php

namespace App\Modules\AcademicLoad\Database\Factories;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Carga en borrador de un departamento en un campus y un periodo.
 *
 * @extends Factory<AcademicLoad>
 */
#[UseModel(AcademicLoad::class)]
class AcademicLoadFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'term_id' => Term::factory(),
            'campus_id' => Campus::factory(),
            'department_id' => Department::factory(),
            'status' => LoadStatus::Draft,
            'current_version' => 0,
        ];
    }

    /**
     * La carga en otro estado, sin los datos de quién la cerró, aprobó o publicó.
     */
    public function inStatus(LoadStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
