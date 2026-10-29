<?php

namespace App\Modules\Academic\Models;

use App\Modules\Academic\Database\Factories\StudyPlanSubjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Una materia en la retícula de un plan: su semestre, su especialidad (del mismo plan) y si es obligatoria.
 * Una vez por plan y materia; quitarla es borrado lógico.
 */
#[Fillable(['study_plan_id', 'subject_id', 'semester', 'specialty_id', 'is_required'])]
#[UseFactory(StudyPlanSubjectFactory::class)]
class StudyPlanSubject extends Model
{
    /** @use HasFactory<StudyPlanSubjectFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'semester' => 'integer',
            'is_required' => 'boolean',
        ];
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
     * @return BelongsTo<Specialty, $this>
     */
    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class);
    }
}
