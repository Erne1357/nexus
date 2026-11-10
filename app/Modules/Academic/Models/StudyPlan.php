<?php

namespace App\Modules\Academic\Models;

use App\Modules\Academic\Database\Factories\StudyPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Plan de estudios de una carrera: una retícula por versión. `code` es la clave natural. Los inactivos se
 * conservan porque hay alumnos cursándolos. Una carrera tiene un solo plan actual (`is_current`), el que se asigna a
 * los alumnos de nuevo ingreso. Una retícula histórica (`is_historical`, D18) es un plan sin alumnos inscritos en el
 * periodo actual: sus materias sin semestre en el SII esperan en «Por validar» sin contar como trabajo pendiente.
 */
#[Fillable(['career_id', 'code', 'name', 'version', 'semesters_count', 'total_credits', 'is_active', 'is_current', 'is_historical', 'valid_from'])]
#[UseFactory(StudyPlanFactory::class)]
class StudyPlan extends Model
{
    /** @use HasFactory<StudyPlanFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'semesters_count' => 'integer',
            'total_credits' => 'integer',
            'is_active' => 'boolean',
            'is_current' => 'boolean',
            'is_historical' => 'boolean',
            'valid_from' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<Career, $this>
     */
    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class);
    }

    /**
     * Las filas de su retícula: la posición de cada materia.
     *
     * @return HasMany<StudyPlanSubject, $this>
     */
    public function placements(): HasMany
    {
        return $this->hasMany(StudyPlanSubject::class);
    }

    /**
     * Sus materias de la migración del SII que esperan semestre (D18), resueltas o no.
     *
     * @return HasMany<StudyPlanSubjectPending, $this>
     */
    public function pendingPlacements(): HasMany
    {
        return $this->hasMany(StudyPlanSubjectPending::class);
    }

    /**
     * Sus materias, con `pivot.semester`, `pivot.specialty_id` y `pivot.is_required`; sin las que se
     * quitaron de la retícula. Solo para leer: attach(), detach() y sync() borrarían filas de verdad o chocarían con una
     * borrada. La retícula se edita por StudyPlanSubject, con borrado lógico y restore().
     *
     * @return BelongsToMany<Subject, $this>
     */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'study_plan_subjects')
            ->withPivot(['semester', 'specialty_id', 'is_required'])
            ->withTimestamps()
            ->wherePivotNull('deleted_at');
    }

    /**
     * @return HasMany<Specialty, $this>
     */
    public function specialties(): HasMany
    {
        return $this->hasMany(Specialty::class);
    }

    /**
     * Su seriación.
     *
     * @return HasMany<SubjectPrerequisite, $this>
     */
    public function prerequisites(): HasMany
    {
        return $this->hasMany(SubjectPrerequisite::class);
    }
}
