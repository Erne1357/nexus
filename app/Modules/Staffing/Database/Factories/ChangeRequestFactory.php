<?php

namespace App\Modules\Staffing\Database\Factories;

use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Enums\ChangeRequestKind;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Models\ChangeRequest;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Solicitud pendiente de cambio de horario de un grupo, hecha por un usuario cualquiera (sin docente).
 *
 * @extends Factory<ChangeRequest>
 */
#[UseModel(ChangeRequest::class)]
class ChangeRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'term_id' => fn (array $attributes): string => Group::query()->findOrFail($attributes['group_id'])->term_id,
            'campus_id' => fn (array $attributes): string => Group::query()->findOrFail($attributes['group_id'])->campus_id,
            'department_id' => fn (array $attributes): string => Group::query()->findOrFail($attributes['group_id'])->department_id,
            'requested_by' => User::factory(),
            'teacher_id' => null,
            'kind' => ChangeRequestKind::Schedule,
            'current' => [],
            'proposed' => ['description' => 'Pasar la clase a los martes de 7 a 9.'],
            'reason' => 'Empalme con otra clase.',
            'status' => ChangeRequestStatus::Pending,
        ];
    }

    public function forGroup(Group $group): static
    {
        return $this->state([
            'group_id' => $group->id,
            'term_id' => $group->term_id,
            'campus_id' => $group->campus_id,
            'department_id' => $group->department_id,
        ]);
    }

    public function pending(): static
    {
        return $this->state(['status' => ChangeRequestStatus::Pending]);
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => ChangeRequestStatus::Approved,
            'decided_by' => User::factory(),
            'decided_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'status' => ChangeRequestStatus::Rejected,
            'decided_by' => User::factory(),
            'decided_at' => now(),
            'decision_comment' => 'No hay otro horario disponible.',
        ]);
    }

    public function applied(): static
    {
        return $this->state(fn (): array => [
            'status' => ChangeRequestStatus::Applied,
            'decided_by' => User::factory(),
            'decided_at' => now()->subHour(),
            'applied_by' => User::factory(),
            'applied_at' => now(),
        ]);
    }

    public function schedule(): static
    {
        return $this->state(['kind' => ChangeRequestKind::Schedule]);
    }

    public function room(): static
    {
        return $this->state(['kind' => ChangeRequestKind::Room]);
    }
}
