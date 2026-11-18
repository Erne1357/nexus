<?php

namespace App\Modules\Solver\Database\Factories;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Solver\Enums\RunScope;
use App\Modules\Solver\Enums\SolverRunStatus;
use App\Modules\Solver\Models\SolverRun;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Una corrida en cola de la carga presencial de un departamento, con el motor de prueba y una entrada mínima.
 *
 * @extends Factory<SolverRun>
 */
#[UseModel(SolverRun::class)]
class SolverRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'term_id' => Term::factory(),
            'modality_id' => fn (): ?string => Modality::withTrashed()->where('code', 'PRES')->value('id'),
            'scope_kind' => RunScope::Department,
            'department_id' => Department::factory(),
            'career_id' => null,
            'engine' => 'fixed',
            'status' => SolverRunStatus::Queued,
            'input' => ['version' => 1],
            'created_by' => User::factory(),
        ];
    }

    /**
     * Una corrida de exploración del coordinador sobre su carrera.
     */
    public function forCareer(): static
    {
        return $this->state(['scope_kind' => RunScope::Career, 'department_id' => null, 'career_id' => Career::factory()]);
    }

    /**
     * Terminada, con salida.
     */
    public function done(): static
    {
        return $this->state(['status' => SolverRunStatus::Done, 'started_at' => now()->subMinute(), 'finished_at' => now(), 'output' => ['version' => 1, 'status' => 'feasible']]);
    }
}
