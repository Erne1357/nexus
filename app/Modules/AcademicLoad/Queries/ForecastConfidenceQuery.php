<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\AcademicLoad\Enums\ForecastConfidence;
use App\Modules\AcademicLoad\Enums\ForecastEvaluationStatus;
use App\Modules\AcademicLoad\Models\ForecastEvaluation;
use App\Modules\AcademicLoad\Models\ForecastEvaluationRow;
use App\Modules\AcademicLoad\Rules\ForecastAccuracy;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;

/**
 * El rótulo de confianza de la proyección de una carrera (DM-22): se mide con las filas de esa carrera en la última
 * evaluación lista de la modalidad. Con campus, la última lista de ese campus o de todos los de la modalidad
 * (supuesto por confirmar), y solo las filas de ese campus. Sin evaluación o sin filas, «sin medir».
 */
final class ForecastConfidenceQuery
{
    public function forCareer(Career $career, Modality $modality, ?Campus $campus = null): ForecastConfidence
    {
        $evaluation = ForecastEvaluation::query()
            ->where('modality_id', $modality->id)
            ->where('status', ForecastEvaluationStatus::Done)
            ->when($campus !== null, fn ($query) => $query->where(fn ($query) => $query->whereNull('campus_id')->orWhere('campus_id', $campus->id)))
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->first();

        if ($evaluation === null) {
            return ForecastConfidence::Unmeasured;
        }

        $rows = ForecastEvaluationRow::query()
            ->where('evaluation_id', $evaluation->id)
            ->where('career_id', $career->id)
            ->when($campus !== null, fn ($query) => $query->where('campus_id', $campus->id))
            ->get(['projected', 'actual_students', 'baseline'])
            ->map(fn (ForecastEvaluationRow $row): array => ['projected' => $row->projected, 'actual' => $row->actual_students, 'baseline' => $row->baseline]);

        return ForecastAccuracy::of($rows)->confidence();
    }
}
