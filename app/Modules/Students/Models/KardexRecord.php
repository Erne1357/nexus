<?php

namespace App\Modules\Students\Models;

use App\Modules\Academic\Models\Subject;
use App\Modules\Students\Database\Factories\KardexRecordFactory;
use App\Modules\Students\Enums\KardexResult;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Una materia en el historial de un alumno, en un periodo (`term_code`, el código del SII). Una por
 * alumno, materia y periodo; la oportunidad (`attempt`) dice si es la primera vez que la cursa.
 */
#[Fillable(['student_id', 'subject_id', 'term_code', 'grade', 'result', 'attempt'])]
#[UseFactory(KardexRecordFactory::class)]
class KardexRecord extends Model
{
    /** @use HasFactory<KardexRecordFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'grade' => 'decimal:2',
            'result' => KardexResult::class,
            'attempt' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
