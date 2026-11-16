<?php

namespace App\Modules\AcademicLoad\Models;

use App\Modules\AcademicLoad\Database\Factories\ForecastEvaluationFactory;
use App\Modules\AcademicLoad\Enums\ForecastEvaluationStatus;
use App\Modules\Core\Models\Campus;
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
 * Una evaluación «como si» de la proyección de demanda de un periodo, modalidad y campus (campus nulo: todos los de la
 * modalidad). `method` es la versión de la fórmula que dio Demand; `totals` trae las métricas globales (forma libre).
 */
#[Fillable(['term_id', 'modality_id', 'campus_id', 'status', 'method', 'history_terms', 'totals', 'requested_by', 'started_at', 'finished_at', 'error'])]
#[UseFactory(ForecastEvaluationFactory::class)]
class ForecastEvaluation extends Model
{
    /** @use HasFactory<ForecastEvaluationFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => ForecastEvaluationStatus::class,
            'history_terms' => 'integer',
            'totals' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
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
     * @return BelongsTo<Modality, $this>
     */
    public function modality(): BelongsTo
    {
        return $this->belongsTo(Modality::class);
    }

    /**
     * @return BelongsTo<Campus, $this>
     */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return HasMany<ForecastEvaluationRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(ForecastEvaluationRow::class, 'evaluation_id');
    }
}
