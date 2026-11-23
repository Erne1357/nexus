<?php

namespace App\Modules\Core\Database\Factories;

use App\Modules\Core\Enums\MailProvider;
use App\Modules\Core\Models\MailConnection;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * La cuenta de envío de Microsoft conectada por un admin, con un token que vale una hora más.
 *
 * @extends Factory<MailConnection>
 */
#[UseModel(MailConnection::class)]
class MailConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => MailProvider::Microsoft,
            'account_email' => 'nexus@cdjuarez.tecnm.mx',
            'access_token' => 'at-'.Str::random(20),
            'refresh_token' => 'rt-'.Str::random(20),
            'access_token_expires_at' => now()->addHour(),
            'connected_by' => User::factory()->admin(),
            'connected_at' => now(),
            'last_used_at' => null,
            'last_error' => null,
            'expired_at' => null,
        ];
    }

    /**
     * La que Microsoft ya no renueva: hay que reconectar.
     */
    public function expired(): static
    {
        return $this->state(['expired_at' => now(), 'last_error' => 'La conexión con Microsoft venció (invalid_grant).']);
    }
}
