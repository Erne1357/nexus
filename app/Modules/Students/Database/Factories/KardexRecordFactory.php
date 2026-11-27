<?php

namespace App\Modules\Students\Database\Factories;

use App\Modules\Academic\Models\Subject;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Materia aprobada con 85 en primera oportunidad, en Ago-Dic 2025.
 *
 * @extends Factory<KardexRecord>
 */
#[UseModel(KardexRecord::class)]
class KardexRecordFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'subject_id' => Subject::factory(),
            'term_code' => '20253',
            'grade' => 85,
            'result' => KardexResult::Aprobada,
            'attempt' => 1,
        ];
    }

    public function failed(): static
    {
        return $this->state(['grade' => 50, 'result' => KardexResult::Reprobada]);
    }

    /**
     * La está cursando: todavía sin calificación.
     */
    public function inProgress(): static
    {
        return $this->state(['grade' => null, 'result' => KardexResult::Cursando]);
    }
}
