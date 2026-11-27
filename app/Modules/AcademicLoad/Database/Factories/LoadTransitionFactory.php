<?php

namespace App\Modules\AcademicLoad\Database\Factories;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadTransition;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Transición de borrador a propuesta de una carga.
 *
 * @extends Factory<LoadTransition>
 */
#[UseModel(LoadTransition::class)]
class LoadTransitionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academic_load_id' => AcademicLoad::factory(),
            'from_status' => LoadStatus::Draft,
            'to_status' => LoadStatus::Proposed,
            'user_id' => User::factory(),
            'comment' => null,
        ];
    }
}
