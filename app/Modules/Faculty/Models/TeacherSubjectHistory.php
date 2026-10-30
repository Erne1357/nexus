<?php

namespace App\Modules\Faculty\Models;

use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Faculty\Database\Factories\TeacherSubjectHistoryFactory;
use App\Modules\Faculty\Enums\HistorySource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Una vez que el docente impartió un grupo de una materia (una fila por grupo, confirmado el 30 de octubre
 * de 2026). Alimenta el desempate del ranking. `term_code` es el código del SII, no llave a `terms`.
 */
#[Fillable(['teacher_id', 'subject_id', 'term_code', 'campus_id', 'group_code', 'source'])]
#[UseFactory(TeacherSubjectHistoryFactory::class)]
class TeacherSubjectHistory extends Model
{
    /** @use HasFactory<TeacherSubjectHistoryFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'teacher_subject_history';

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return ['source' => HistorySource::class];
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * @return BelongsTo<Campus, $this>
     */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }
}
