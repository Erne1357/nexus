<?php

namespace App\Modules\Faculty\Database\Factories;

use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Plaza de medio tiempo sin límites de vigencia.
 *
 * @extends Factory<TeacherPosition>
 */
#[UseModel(TeacherPosition::class)]
class TeacherPositionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'teacher_id' => Teacher::factory(),
            'kind' => PositionKind::MedioTiempo,
            'base_hours' => 20,
            'valid_from' => null,
            'valid_to' => null,
        ];
    }

    public function tiempoCompleto(): static
    {
        return $this->ofKind(PositionKind::TiempoCompleto);
    }

    public function tresCuartos(): static
    {
        return $this->ofKind(PositionKind::TresCuartos);
    }

    public function medioTiempo(): static
    {
        return $this->ofKind(PositionKind::MedioTiempo);
    }

    public function asignatura(int $hours): static
    {
        return $this->state(['kind' => PositionKind::Asignatura, 'base_hours' => $hours]);
    }

    public function honorarios(): static
    {
        return $this->ofKind(PositionKind::Honorarios);
    }

    /**
     * Terminó hace un año.
     */
    public function expired(): static
    {
        return $this->state(['valid_from' => today()->subYears(2)->toDateString(), 'valid_to' => today()->subYear()->toDateString()]);
    }

    /**
     * Empieza dentro de un año.
     */
    public function future(): static
    {
        return $this->state(['valid_from' => today()->addYear()->toDateString(), 'valid_to' => null]);
    }

    private function ofKind(PositionKind $kind): static
    {
        return $this->state(['kind' => $kind, 'base_hours' => $kind->fixedHours()]);
    }
}
