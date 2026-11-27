<?php

namespace App\Modules\Core\Database\Factories;

use App\Modules\Core\Models\HeldPositionMail;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Un correo de puesto en espera, del jefe de una unidad cualquiera. La notificación no se puede volver a armar: sirve
 * para la pantalla, no para liberarlo.
 *
 * @extends Factory<HeldPositionMail>
 */
#[UseModel(HeldPositionMail::class)]
class HeldPositionMailFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'notification_id' => fn (): string => (string) Str::uuid7(),
            'user_id' => User::factory(),
            'role' => 'jefe_departamento',
            'org_unit_id' => null,
            'career_id' => null,
            'campus_id' => null,
            'modality_id' => null,
            'kind' => 'CatalogReviewsPending',
            'subject' => 'Tienes catálogos por validar',
            'notification' => 'N;',
            'held_at' => now(),
        ];
    }

    public function failed(int $attempts = 3): static
    {
        return $this->state(['failed_at' => now(), 'attempts' => $attempts]);
    }
}
