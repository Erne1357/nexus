<?php

namespace App\Modules\Staffing\Database\Factories;

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\Selection;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Selección en borrador con la ventana por omisión (09:00 a 14:00, lunes a viernes, turnos de 30 minutos).
 *
 * @extends Factory<Selection>
 */
#[UseModel(Selection::class)]
class SelectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'term_id' => Term::factory(),
            'modality_id' => Modality::factory(),
            'department_id' => Department::factory(),
            'status' => SelectionStatus::Draft,
        ];
    }
}
