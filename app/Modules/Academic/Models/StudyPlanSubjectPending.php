<?php

namespace App\Modules\Academic\Models;

use App\Modules\Academic\Database\Factories\StudyPlanSubjectPendingFactory;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una materia que la migración del SII no pudo poner en la retícula de un plan porque no tiene semestre (D18):
 * espera a que Servicios Escolares le asigne uno (D19). Una vez por plan y materia. Resuelta, conserva su fila con
 * `resolved_at` y `resolved_by`; no tiene borrado lógico (supuesto por confirmar). `sii_grid` es la retícula del SII
 * de la que salió (`carrera/retícula`).
 */
#[Fillable(['study_plan_id', 'subject_id', 'specialty_id', 'source', 'sii_grid', 'note', 'resolved_at', 'resolved_by'])]
#[UseFactory(StudyPlanSubjectPendingFactory::class)]
class StudyPlanSubjectPending extends Model
{
    /** @use HasFactory<StudyPlanSubjectPendingFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
