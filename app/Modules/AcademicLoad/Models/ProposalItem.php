<?php

namespace App\Modules\AcademicLoad\Models;

use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Database\Factories\ProposalItemFactory;
use App\Modules\Core\Models\Campus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una materia de la propuesta de carrera en un campus (M6): el plan y el semestre de la retícula de donde se tomó, los
 * alumnos posibles de ese campus al armarla y lo que corrige el coordinador (alumnos esperados, grupos solicitados y
 * notas). Su departamento es el de la materia. Los grupos que nacen de ella son de su campus (M8). `campus_id` es nulo
 * solo en una modalidad sin salones, que lleva una sola columna (MS5).
 */
#[Fillable(['career_proposal_id', 'campus_id', 'subject_id', 'study_plan_id', 'semester', 'eligible_students', 'expected_students', 'requested_groups', 'notes'])]
#[UseFactory(ProposalItemFactory::class)]
class ProposalItem extends Model
{
    /** @use HasFactory<ProposalItemFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'semester' => 'integer',
            'eligible_students' => 'integer',
            'expected_students' => 'integer',
            'requested_groups' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CareerProposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(CareerProposal::class, 'career_proposal_id');
    }

    /**
     * El campus de la fila (su columna en la propuesta); null en una modalidad sin salones (MS5).
     *
     * @return BelongsTo<Campus, $this>
     */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

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

    /**
     * Las filas que piden al menos un grupo.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function requested(Builder $query): void
    {
        $query->where('proposal_items.requested_groups', '>', 0);
    }
}
