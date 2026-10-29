<?php

namespace App\Modules\Students\Models;

use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Students\Database\Factories\StudentProgressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Avance reticular de un alumno en su plan: créditos y materias aprobadas, las materias del plan que no ha
 * aprobado (`subjects_pending`) y las que de esas ya puede cursar (`subjects_available`), como listas de ids
 * de materia. Lo recalcula el job RecomputeStudentProgress; no se edita a mano.
 */
#[Table('student_progress')]
#[Fillable(['student_id', 'study_plan_id', 'credits_approved', 'subjects_approved', 'subjects_pending', 'subjects_available', 'computed_at'])]
#[UseFactory(StudentProgressFactory::class)]
class StudentProgress extends Model
{
    /** @use HasFactory<StudentProgressFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credits_approved' => 'integer',
            'subjects_approved' => 'integer',
            'subjects_pending' => 'array',
            'subjects_available' => 'array',
            'computed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<StudyPlan, $this>
     */
    public function studyPlan(): BelongsTo
    {
        return $this->belongsTo(StudyPlan::class);
    }
}
