<?php

namespace App\Modules\Faculty\Models;

use App\Modules\Faculty\Database\Factories\TeacherEvaluationFactory;
use App\Modules\Faculty\Enums\EvaluationKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Evaluación docente de un periodo del SII, en escala de 1 a 5 (confirmado el 30 de octubre de 2026). Usa
 * `term_code`, no `term_id`: vienen periodos que `terms` no tiene.
 */
#[Fillable(['teacher_id', 'term_code', 'kind', 'score', 'source'])]
#[UseFactory(TeacherEvaluationFactory::class)]
class TeacherEvaluation extends Model
{
    /** @use HasFactory<TeacherEvaluationFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'teacher_evaluations';

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'kind' => EvaluationKind::class,
            'score' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }
}
