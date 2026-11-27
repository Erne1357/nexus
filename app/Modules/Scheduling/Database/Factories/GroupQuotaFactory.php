<?php

namespace App\Modules\Scheduling\Database\Factories;

use App\Modules\Academic\Models\Career;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Cupo de 10 lugares de una carrera que no es la dueña. El cupo dueño lo da `GroupFactory::withQuota()`.
 *
 * @extends Factory<GroupQuota>
 */
#[UseModel(GroupQuota::class)]
class GroupQuotaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'career_id' => Career::factory(),
            'max_seats' => 10,
            'is_owner' => false,
        ];
    }
}
