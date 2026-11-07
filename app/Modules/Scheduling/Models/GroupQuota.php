<?php

namespace App\Modules\Scheduling\Models;

use App\Modules\Academic\Models\Career;
use App\Modules\Scheduling\Database\Factories\GroupQuotaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cupo de una carrera en un grupo (glosario, «Cupo por carrera»; ADR-0006): lugares reservados para esa carrera. La
 * suma de los cupos no excede la capacidad del grupo (HC-07) y el cupo dueño es el de la carrera dueña.
 */
#[Fillable(['group_id', 'career_id', 'max_seats', 'is_owner'])]
#[UseFactory(GroupQuotaFactory::class)]
class GroupQuota extends Model
{
    /** @use HasFactory<GroupQuotaFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_seats' => 'integer',
            'is_owner' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * @return BelongsTo<Career, $this>
     */
    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class);
    }
}
