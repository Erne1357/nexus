<?php

namespace App\Modules\AcademicLoad\Database\Factories;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Préstamo propuesto: un salón del dueño (`to`) en el campus del acuerdo, el lunes de 9:00 a 11:00, para un grupo
 * del departamento que lo pide (`from`). `sharedGroup()` lo vuelve el cupo de una carrera del departamento `to`.
 *
 * @extends Factory<InterdepartmentalAgreement>
 */
#[UseModel(InterdepartmentalAgreement::class)]
class InterdepartmentalAgreementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'term_id' => Term::factory(),
            'campus_id' => Campus::factory(),
            'kind' => AgreementKind::RoomLoan,
            'from_department_id' => Department::factory(),
            'to_department_id' => Department::factory(),
            'group_id' => fn (): string => (string) Str::uuid7(),
            'room_id' => fn (array $attributes): string => Room::factory()
                ->create([
                    'building_id' => Building::factory()->create(['campus_id' => $attributes['campus_id']])->id,
                    'department_id' => $attributes['to_department_id'],
                ])->id,
            'day' => 1,
            'starts_at' => '09:00',
            'ends_at' => '11:00',
            'career_id' => null,
            'max_seats' => null,
            'status' => AgreementStatus::Proposed,
            'requested_by' => User::factory(),
        ];
    }

    /**
     * El cupo de una carrera del departamento que decide, en un grupo del que lo pide.
     */
    public function sharedGroup(int $seats = 5): static
    {
        return $this->state([
            'kind' => AgreementKind::SharedGroup,
            'room_id' => null,
            'day' => null,
            'starts_at' => null,
            'ends_at' => null,
            'career_id' => fn (array $attributes): string => Career::factory()->create(['department_id' => $attributes['to_department_id']])->id,
            'max_seats' => $seats,
        ]);
    }

    public function accepted(): static
    {
        return $this->inStatus(AgreementStatus::Accepted);
    }

    public function rejected(string $comment = 'Ese bloque ya lo usa otro grupo.'): static
    {
        return $this->inStatus(AgreementStatus::Rejected)->state(['comment' => $comment]);
    }

    /**
     * En ese estado; fuera de `proposed`, con quién lo decidió y cuándo (y motivo si es rechazo).
     */
    public function inStatus(AgreementStatus $status): static
    {
        if ($status === AgreementStatus::Proposed) {
            return $this->state(['status' => $status, 'decided_by' => null, 'decided_at' => null]);
        }

        return $this->state(fn (array $attributes): array => [
            'status' => $status,
            'comment' => $status === AgreementStatus::Rejected ? ($attributes['comment'] ?? 'Ese bloque ya lo usa otro grupo.') : ($attributes['comment'] ?? null),
            'decided_by' => User::factory(),
            'decided_at' => now(),
        ]);
    }
}
