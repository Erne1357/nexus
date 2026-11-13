<?php

namespace App\Modules\AcademicLoad\Models;

use App\Modules\Academic\Models\Career;
use App\Modules\AcademicLoad\Database\Factories\CareerProposalFactory;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\Core\Models\Modality;
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
 * Propuesta de carrera: las materias y los grupos que los coordinadores piden abrir para su carrera en una modalidad y
 * un periodo (reglas-de-negocio.md, "Propuesta de carrera"). Una por periodo, modalidad y carrera (M6), con una fila
 * por materia y campus (ProposalItem). Se arma con StartCareerProposal, se corrige con UpdateProposalItem y se envía
 * con SubmitCareerProposal. Guarda sus alumnos posibles, congelados al armarla: el total (`eligible_students`) y los de
 * cada campus (`eligible_students_by_campus`, por id del campus y `none` para la columna sin campus).
 */
#[Fillable(['term_id', 'modality_id', 'career_id', 'eligible_students', 'eligible_students_by_campus', 'status', 'submitted_by', 'submitted_at'])]
#[UseFactory(CareerProposalFactory::class)]
class CareerProposal extends Model
{
    /** @use HasFactory<CareerProposalFactory> */
    use HasFactory, HasUuids;

    /** La llave de la columna sin campus (MS5) en `eligible_students_by_campus`. */
    public const string WITHOUT_CAMPUS = 'none';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProposalStatus::class,
            'eligible_students' => 'integer',
            'eligible_students_by_campus' => 'array',
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
     * Su modalidad del catálogo (M6, ADR-0025).
     *
     * @return BelongsTo<Modality, $this>
     */
    public function modality(): BelongsTo
    {
        return $this->belongsTo(Modality::class);
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
     * Sus filas, una por materia y campus (M6); sin campus en una modalidad sin salones (MS5).
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
