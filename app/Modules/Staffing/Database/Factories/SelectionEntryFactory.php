<?php

namespace App\Modules\Staffing\Database\Factories;

use App\Modules\Faculty\Models\Teacher;
use App\Modules\Staffing\Enums\SelectionEntrySource;
use App\Modules\Staffing\Enums\SelectionEntryStatus;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Models\SelectionEntry;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Renglón del CSV de un docente de tiempo completo que cumple todos los criterios, esperando su turno.
 *
 * @extends Factory<SelectionEntry>
 */
#[UseModel(SelectionEntry::class)]
class SelectionEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'selection_id' => Selection::factory(),
            'teacher_id' => Teacher::factory(),
            'source' => SelectionEntrySource::Csv,
            'base_hours' => 20,
            'auto_eval' => true,
            'prior_compliance' => true,
            'partials_1' => true,
            'partials_2' => true,
            'seniority_years' => 5,
            'plaza_tier' => 0,
            'failed_criteria' => 0,
            'tier' => 0,
            'position' => 0,
            'slot_index' => 0,
            'status' => SelectionEntryStatus::Waiting,
        ];
    }
}
