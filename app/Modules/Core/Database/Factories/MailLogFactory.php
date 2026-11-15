<?php

namespace App\Modules\Core\Database\Factories;

use App\Modules\Core\Enums\MailStatus;
use App\Modules\Core\Models\MailLog;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Un correo enviado, sin usuario reconocido.
 *
 * @extends Factory<MailLog>
 */
#[UseModel(MailLog::class)]
class MailLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'to' => fake()->unique()->safeEmail(),
            'subject' => fake()->sentence(4),
            'kind' => 'AccessInvitation',
            'user_id' => null,
            'status' => MailStatus::Sent,
            'error' => null,
            'sent_at' => now(),
        ];
    }

    public function failed(string $error = 'No se pudo conectar con el servidor de correo.'): static
    {
        return $this->state(['status' => MailStatus::Failed, 'error' => $error]);
    }
}
