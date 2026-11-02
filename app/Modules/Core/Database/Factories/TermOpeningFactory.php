<?php

namespace App\Modules\Core\Database\Factories;

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Un periodo abierto ahora en un campus activo por un usuario.
 *
 * @extends Factory<TermOpening>
 */
#[UseModel(TermOpening::class)]
class TermOpeningFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'term_id' => Term::factory(),
            'campus_id' => Campus::factory(),
            'opened_by' => User::factory(),
            'opened_at' => now(),
        ];
    }
}
