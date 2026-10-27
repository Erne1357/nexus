<?php

namespace App\Modules\Core\Database\Factories;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Usuario activo que ya cambió su contraseña inicial.
 *
 * @extends Factory<User>
 */
#[UseModel(User::class)]
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => fake()->unique()->numerify('########'),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'is_active' => true,
            'must_change_password' => false,
            'remember_token' => Str::random(10),
        ];
    }
}
