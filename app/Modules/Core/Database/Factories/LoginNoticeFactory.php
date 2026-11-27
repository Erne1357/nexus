<?php

namespace App\Modules\Core\Database\Factories;

use App\Modules\Core\Models\LoginNotice;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Un aviso activo, sin foto ni enlace y sin fechas: vigente siempre.
 *
 * @extends Factory<LoginNotice>
 */
#[UseModel(LoginNotice::class)]
class LoginNoticeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->unique()->sentence(3),
            'image_path' => null,
            'link_url' => null,
            'sort_order' => 0,
            'starts_at' => null,
            'ends_at' => null,
            'is_active' => true,
        ];
    }
}
