<?php

namespace App\Modules\Students\Models;

use App\Modules\Academic\Models\Subject;
use App\Modules\Students\Database\Factories\KardexRecordFactory;
use App\Modules\Students\Enums\KardexResult;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
     * La calificación como se lee en la ficha: sin ceros de más y sin redondear (`88.00` es «88»; `69.60`,
     * «69.6»); null si no trae.
     *
     * @return Attribute<string|null, never>
     */
    protected function gradeLabel(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->grade === null ? null : rtrim(rtrim((string) $this->grade, '0'), '.'));
    }

    /**
     * La oportunidad con su nombre: 1 ordinaria, 2 repetición, 3 curso especial (reglas-de-negocio.md,
     * "Retícula del alumno").
     *
     * @return Attribute<string, never>
     */
    protected function attemptLabel(): Attribute
    {
        return Attribute::get(fn (): string => $this->attempt.' · '.match ($this->attempt) {
            1 => 'ordinaria',
            2 => 'repetición',
            default => 'curso especial',
        });
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
