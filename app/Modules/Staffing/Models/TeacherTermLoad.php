<?php

namespace App\Modules\Staffing\Models;

use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Staffing\Database\Factories\TeacherTermLoadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Horas de un docente en un periodo: base, externas, asignadas, tope y restantes (negativas si rebasa el tope).
 * Tabla derivada para tableros y HC-08.
 */
#[Fillable(['teacher_id', 'term_id', 'base_hours', 'external_hours', 'assigned_hours', 'limit_hours', 'remaining_hours', 'groups_count', 'computed_at'])]
#[UseFactory(TeacherTermLoadFactory::class)]
class TeacherTermLoad extends Model
{
    /** @use HasFactory<TeacherTermLoadFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_hours' => 'integer',
            'external_hours' => 'integer',
            'assigned_hours' => 'integer',
            'limit_hours' => 'integer',
            'remaining_hours' => 'integer',
            'groups_count' => 'integer',
            'computed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
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
}
