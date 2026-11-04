<?php

namespace App\Modules\AcademicLoad\Models;

use App\Modules\Academic\Models\Career;
use App\Modules\AcademicLoad\Database\Factories\CareerProposalFactory;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Propuesta de carrera: las materias y los grupos que el coordinador pide abrir para su carrera en un campus y
 * un periodo (reglas-de-negocio.md, "Propuesta de carrera"). Una por periodo, campus y carrera. Se arma con
 * StartCareerProposal, se corrige con UpdateProposalItem y se envía con SubmitCareerProposal.
 */
#[Fillable(['term_id', 'campus_id', 'career_id', 'eligible_students', 'status', 'submitted_by', 'submitted_at'])]
#[UseFactory(CareerProposalFactory::class)]
class CareerProposal extends Model
{
    /** @use HasFactory<CareerProposalFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProposalStatus::class,
            'eligible_students' => 'integer',
            'submitted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Term, $this>
     */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /**
     * @return BelongsTo<Campus, $this>
     */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /**
     * @return BelongsTo<Career, $this>
     */
    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class);
    }

    /**
     * Quién la envió la última vez.
     *
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * Sus filas, una por materia.
     *
     * @return HasMany<ProposalItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ProposalItem::class);
    }

    /**
     * La parte de cada departamento involucrado.
     *
     * @return HasMany<ProposalReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(ProposalReview::class);
    }
}
