<?php

namespace App\Modules\Academic\Models;

use App\Modules\Academic\Database\Factories\SubjectFactory;
use App\Modules\Academic\Enums\SubjectKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Materia global, con el departamento que la imparte. `code` es la clave natural. Su posición y su
 * seriación son de cada plan. `kind` puede venir vacío: la exportación del SII no lo trae.
 */
#[Fillable(['code', 'name', 'department_id', 'theory_hours', 'practice_hours', 'credits', 'kind', 'needs_lab', 'is_active'])]
#[UseFactory(SubjectFactory::class)]
class Subject extends Model
{
    /** @use HasFactory<SubjectFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'theory_hours' => 'integer',
            'practice_hours' => 'integer',
            'credits' => 'integer',
            'kind' => SubjectKind::class,
            'needs_lab' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Los planes en cuya retícula está, con su posición en `pivot`; sin los que la quitaron. Solo para leer: attach(), detach() y sync() borrarían filas de verdad o chocarían con una
     * borrada. La retícula se edita por StudyPlanSubject, con borrado lógico y restore().
     *
     * @return BelongsToMany<StudyPlan, $this>
     */
    public function studyPlans(): BelongsToMany
    {
        return $this->belongsToMany(StudyPlan::class, 'study_plan_subjects')
            ->withPivot(['semester', 'specialty_id', 'is_required'])
            ->withTimestamps()
            ->wherePivotNull('deleted_at');
    }
}
