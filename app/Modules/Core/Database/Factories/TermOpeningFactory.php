<?php

namespace App\Modules\Core\Database\Factories;

use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Un periodo abierto ahora en Presencial (M4) por un usuario. El catálogo de modalidades lo siembra su migración; otra
 * modalidad se pasa con `for($modality)` o `modality_id`.
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
            'modality_id' => fn (): ?string => Modality::query()->where('code', 'PRES')->value('id'),
            'opened_by' => User::factory(),
            'opened_at' => now(),
        ];
    }
}
