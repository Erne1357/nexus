<?php

namespace App\Modules\Staffing\Models;

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Database\Factories\StaffingRankingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lugar de un docente en el ranking visible de un grupo, con su puntaje y el desglose que lo explica. Tabla derivada:
 * la reescribe el recálculo del ranking.
 */
#[Fillable(['term_id', 'campus_id', 'modality_id', 'group_id', 'teacher_id', 'rank', 'score', 'breakdown', 'computed_at'])]
#[UseFactory(StaffingRankingFactory::class)]
class StaffingRanking extends Model
{
    /** @use HasFactory<StaffingRankingFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rank' => 'integer',
            'score' => 'decimal:2',
            'breakdown' => 'array',
            'computed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<StaffingRanking>  $query
     */
    #[Scope]
    protected function inRankOrder(Builder $query): void
    {
        $query->orderBy('rank');
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
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
     * Su modalidad del catálogo (ADR-0025): ampliación de la ronda 2, copia de la de su grupo; aún no la lee nadie.
     *
     * @return BelongsTo<Modality, $this>
     */
    public function modality(): BelongsTo
    {
        return $this->belongsTo(Modality::class);
    }
}
