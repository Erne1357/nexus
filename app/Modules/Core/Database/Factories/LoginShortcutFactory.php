<?php

namespace App\Modules\Core\Database\Factories;

use App\Modules\Core\Enums\LoginShortcutKind;
use App\Modules\Core\Models\LoginShortcut;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Un atajo activo de tipo enlace, sin fechas: vigente siempre.
 *
 * @extends Factory<LoginShortcut>
 */
#[UseModel(LoginShortcut::class)]
class LoginShortcutFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => LoginShortcutKind::Link,
            'title' => fake()->unique()->words(2, true),
            'description' => null,
            'url' => fake()->url(),
            'file_path' => null,
            'sort_order' => 0,
            'starts_at' => null,
            'ends_at' => null,
            'is_active' => true,
        ];
    }

    /** Una descarga de un archivo del disco público. */
    public function download(string $path = 'login/atajos/archivo.pdf'): static
    {
        return $this->state(['kind' => LoginShortcutKind::Download, 'url' => null, 'file_path' => $path]);
    }
}
