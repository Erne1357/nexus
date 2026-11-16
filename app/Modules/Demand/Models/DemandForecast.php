<?php

namespace App\Modules\Demand\Models;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Demand\Database\Factories\DemandForecastFactory;
use App\Modules\Demand\Enums\ForecastMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La proyección de una materia de una carrera en un campus para un periodo y una modalidad (tarea 5.1): los elegibles
 * proyectados (con quienes cursan hoy la seriación, `pending_prerequisite_students` de ellos), quienes cursan hoy la
 * materia, el nuevo ingreso en primer semestre, las tasas históricas y cuántos periodos las dieron, y lo que sale de la
 * fórmula (DemandForecaster): repetidores, esperados y grupos sugeridos, con su método y su versión. `needs_review` marca
 * una materia de especialidad, que depende de qué especialidad elige cada alumno. `campus_id` es nulo solo en una
 * modalidad sin salones (MS5).
 */
#[Fillable([
    'term_id', 'modality_id', 'campus_id', 'career_id', 'subject_id',
    'eligible_students', 'pending_prerequisite_students', 'current_students', 'new_students',
    'non_enrollment_rate', 'failure_rate', 'history_terms_used',
    'expected_repeaters', 'expected_students', 'suggested_groups',
    'method', 'formula_version', 'needs_review', 'computed_at',
])]
#[UseFactory(DemandForecastFactory::class)]
class DemandForecast extends Model
{
    /** @use HasFactory<DemandForecastFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'eligible_students' => 'integer',
            'pending_prerequisite_students' => 'integer',
            'current_students' => 'integer',
            'new_students' => 'integer',
            'non_enrollment_rate' => 'float',
            'failure_rate' => 'float',
            'history_terms_used' => 'integer',
            'expected_repeaters' => 'integer',
            'expected_students' => 'integer',
            'suggested_groups' => 'integer',
            'method' => ForecastMethod::class,
            'needs_review' => 'boolean',
            'computed_at' => 'immutable_datetime',
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
     * El campus de la fila; null en una modalidad sin salones (MS5).
     *
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
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
