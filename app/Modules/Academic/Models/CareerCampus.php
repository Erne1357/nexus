<?php

namespace App\Modules\Academic\Models;

use App\Modules\Academic\Database\Factories\CareerCampusFactory;
use App\Modules\Core\Models\Campus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Que una carrera se ofrece en un campus (`career_campus`). Una vez por carrera y campus.
 */
#[Table('career_campus')]
#[Fillable(['career_id', 'campus_id', 'is_active'])]
#[UseFactory(CareerCampusFactory::class)]
class CareerCampus extends Model
{
    /** @use HasFactory<CareerCampusFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return BelongsTo<Career, $this>
     */
    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class);
    }

    /**
     * @return BelongsTo<Campus, $this>
     */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }
}
