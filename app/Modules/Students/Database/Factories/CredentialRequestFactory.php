<?php

namespace App\Modules\Students\Database\Factories;

use App\Modules\Students\Enums\CredentialRequestStatus;
use App\Modules\Students\Models\CredentialRequest;
use App\Modules\Students\Models\Student;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Solicitud de reposición recién hecha.
 *
 * @extends Factory<CredentialRequest>
 */
#[UseModel(CredentialRequest::class)]
class CredentialRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'wants_reposition' => true,
            'wants_photo' => false,
            'status' => CredentialRequestStatus::Solicitada,
            'requested_at' => now(),
        ];
    }

    public function delivered(?DateTimeInterface $at = null): static
    {
        return $this->state(['status' => CredentialRequestStatus::Entregada, 'delivered_at' => $at ?? now()]);
    }
}
