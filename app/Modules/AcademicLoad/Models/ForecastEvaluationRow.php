<?php

namespace App\Modules\AcademicLoad\Models;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Database\Factories\ForecastEvaluationRowFactory;
use App\Modules\Core\Models\Campus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo proyectado y lo real de una materia, carrera y campus en una evaluación. `baseline` son los elegibles (la regla
 * de R1) para comparar contra la proyección. El campus es nulo en una modalidad sin salones.
 */
#[Fillable(['evaluation_id', 'career_id', 'campus_id', 'subject_id', 'eligible', 'projected', 'suggested_groups', 'actual_students', 'actual_groups', 'baseline', 'forecast_method'])]
#[UseFactory(ForecastEvaluationRowFactory::class)]
class ForecastEvaluationRow extends Model
{
    /** @use HasFactory<ForecastEvaluationRowFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'eligible' => 'integer',
            'projected' => 'integer',
            'suggested_groups' => 'integer',
            'actual_students' => 'integer',
            'actual_groups' => 'integer',
            'baseline' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ForecastEvaluation, $this>
     */
    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(ForecastEvaluation::class, 'evaluation_id');
    }

    /**
     * @return BelongsTo<Career, $this>
     */
    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class);
    }

    /**
     * @return BelongsTo<Campus, $this>
     */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
