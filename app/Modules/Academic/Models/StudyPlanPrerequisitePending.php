<?php

namespace App\Modules\Academic\Models;

use App\Modules\Academic\Database\Factories\StudyPlanPrerequisitePendingFactory;
use App\Modules\Academic\Enums\PrerequisiteKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un requisito de la seriación del SII que espera (decisión del usuario del 10 de noviembre de 2026, confirmada): la
 * materia o su requisito están en «Por validar» del plan, así que todavía no puede entrar a `subject_prerequisites`.
 * Entra solo cuando las dos quedan en la retícula (ApplyWaitingPrerequisites, desde ResolvePendingPlacement) y queda con
 * `applied_at`. Una vez por plan, materia y requisito; sin borrado lógico (supuesto por confirmar). `sii_grid` es la
 * retícula del SII de la que salió (`carrera/retícula`) y `note`, por qué sigue esperando aunque sus dos materias ya
 * estén en la retícula.
 */
#[Fillable(['study_plan_id', 'subject_id', 'prerequisite_subject_id', 'kind', 'source', 'sii_grid', 'note', 'applied_at'])]
#[UseFactory(StudyPlanPrerequisitePendingFactory::class)]
class StudyPlanPrerequisitePending extends Model
{
    /** @use HasFactory<StudyPlanPrerequisitePendingFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kind' => PrerequisiteKind::class, 'applied_at' => 'datetime'];
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
     * La materia que se pide.
     *
     * @return BelongsTo<Subject, $this>
     */
    public function prerequisite(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'prerequisite_subject_id');
    }
}
