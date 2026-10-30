<?php

namespace App\Modules\Academic\Models;

use App\Modules\Academic\Database\Factories\SpecialtyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Módulo de especialidad de un plan. `code` es único dentro de su plan.
 */
#[Fillable(['study_plan_id', 'code', 'name', 'is_active'])]
#[UseFactory(SpecialtyFactory::class)]
class Specialty extends Model
{
    /** @use HasFactory<SpecialtyFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return BelongsTo<StudyPlan, $this>
     */
    public function studyPlan(): BelongsTo
    {
        return $this->belongsTo(StudyPlan::class);
    }

    /**
     * Las filas de la retícula de su plan que son de esta especialidad, sin las quitadas.
     *
     * @return HasMany<StudyPlanSubject, $this>
     */
    public function placements(): HasMany
    {
        return $this->hasMany(StudyPlanSubject::class);
    }
}
