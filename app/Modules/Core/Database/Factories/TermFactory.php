<?php

namespace App\Modules\Core\Database\Factories;

use App\Modules\Core\Models\Term;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Periodo Ene-Jun de un año cualquiera, que no es el actual.
 *
 * @extends Factory<Term>
 */
#[UseModel(Term::class)]
class TermFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $year = fake()->unique()->numberBetween(2000, 2099);

        return [
            'code' => "{$year}-1",
            'name' => "Ene-Jun {$year}",
            'starts_on' => "{$year}-01-18",
            'ends_on' => "{$year}-06-11",
            'is_current' => false,
        ];
    }

    public function current(): static
    {
        return $this->state(['is_current' => true]);
    }
}
