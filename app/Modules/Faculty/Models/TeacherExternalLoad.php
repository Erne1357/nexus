<?php

namespace App\Modules\Faculty\Models;

use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Database\Factories\TeacherExternalLoadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Horas que un docente da fuera de Nexus en un periodo (por ejemplo TecNM Virtual); se restan de sus horas
 * disponibles (S16, supuesto por confirmar).
 */
#[Fillable(['teacher_id', 'term_id', 'source', 'hours', 'notes'])]
#[UseFactory(TeacherExternalLoadFactory::class)]
class TeacherExternalLoad extends Model
{
    /** @use HasFactory<TeacherExternalLoadFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'teacher_external_loads';

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return ['hours' => 'integer'];
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
