<?php

namespace App\Modules\Academic\Models;

use App\Modules\Academic\Database\Factories\SubjectPrerequisiteFactory;
use App\Modules\Academic\Enums\PrerequisiteKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Seriación de una materia en un plan: su requisito (`prerequisite`) y si es seriación o correquisito. Las dos
 * materias tienen fila en la retícula del plan (la base lo garantiza; quitar una materia de la retícula quita
 * sus seriaciones en la misma Action).
 */
#[Fillable(['study_plan_id', 'subject_id', 'prerequisite_subject_id', 'kind'])]
#[UseFactory(SubjectPrerequisiteFactory::class)]
class SubjectPrerequisite extends Model
{
    /** @use HasFactory<SubjectPrerequisiteFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kind' => PrerequisiteKind::class];
    }

    /**
     * @return BelongsTo<StudyPlan, $this>
     */
    public function studyPlan(): BelongsTo
    {
        return $this->belongsTo(StudyPlan::class);
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * La materia que se pide antes.
     *
     * @return BelongsTo<Subject, $this>
     */
    public function prerequisite(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'prerequisite_subject_id');
    }
}
