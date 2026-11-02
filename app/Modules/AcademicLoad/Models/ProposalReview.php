<?php

namespace App\Modules\AcademicLoad\Models;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Database\Factories\ProposalReviewFactory;
use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La parte de un departamento en una propuesta de carrera: todo lo que la carrera le pide (las filas con grupos
 * de sus materias), que el departamento acepta o regresa con comentario (confirmado el 2 de noviembre de 2026).
 * La crea SubmitCareerProposal; la decisión llega con la bandeja del departamento (tarea 2.8).
 */
#[Fillable(['career_proposal_id', 'department_id', 'status', 'comment', 'decided_by', 'decided_at'])]
#[UseFactory(ProposalReviewFactory::class)]
class ProposalReview extends Model
{
    /** @use HasFactory<ProposalReviewFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProposalReviewStatus::class,
            'decided_at' => 'immutable_datetime',
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
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Quién del departamento la aceptó o la regresó.
     *
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
