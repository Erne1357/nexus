<?php

namespace App\Modules\Academic\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Clave con que el SII conoce una materia, a veces distinta de la de Nexus y por plan. `code` es único en todos los
 * alias. Solo la escribe la importación: sin borrado lógico ni `is_active` (supuesto por confirmar).
 */
#[Fillable(['subject_id', 'study_plan_id', 'code', 'source'])]
class SubjectAlias extends Model
{
    use HasUuids;

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * @return BelongsTo<StudyPlan, $this>
     */
    public function studyPlan(): BelongsTo
    {
        return $this->belongsTo(StudyPlan::class);
    }
}
