<?php

namespace App\Modules\AcademicLoad\Actions;

use App\Modules\AcademicLoad\Enums\ForecastEvaluationStatus;
use App\Modules\AcademicLoad\Jobs\RunForecastEvaluation;
use App\Modules\AcademicLoad\Models\ForecastEvaluation;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Demand\Rules\DemandForecaster;
use App\Modules\Demand\Rules\DemandSettings;
use App\Modules\Demand\Rules\TermCode;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Models\KardexRecord;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pide evaluar la proyección de demanda de un periodo ya cursado en una modalidad, y en un campus o en todos (tarea
 * 5.9b; DM-25 a DM-29). Quién puede pedirla lo decide ForecastEvaluationPolicy::create (DM-24) en el componente que la
 * llama; el comando de consola no pasa por la policy y la pide sin usuario.
 *
 * - DM-28: antes de crear nada valida que se pueda comparar: el periodo no es de verano (la proyección no calcula
 *   verano), el campus no se da en una modalidad sin salones y el periodo tiene kárdex contable (DM-02) en la modalidad
 *   (y el campus). Si no, una ValidationException y no se guarda una evaluación vacía (supuestos por confirmar, salvo el
 *   kárdex).
 * - DM-29: si ya hay una del mismo periodo, modalidad y campus en cola o corriendo, la devuelve sin crear otra ni encolar
 *   nada. Una que no se movió en `RunForecastEvaluation::STALE_AFTER` segundos se da por perdida: queda fallida y se
 *   crea otra. Fuera de eso, evaluar otra vez crea otra evaluación y conserva las anteriores (supuesto por confirmar).
 *
 * Crea la evaluación en cola con la ventana de historia (`demand.history_terms`) y la versión de la fórmula de Demand,
 * deja bitácora («requested») y, después del commit, encola RunForecastEvaluation. Con `$sync` (el comando), la corre en
 * este mismo proceso: lo que lance la corrida sale de aquí, con la evaluación ya fallida.
 */
final class RequestForecastEvaluation
{
    /**
     * @throws ValidationException si el periodo es de verano, el campus sobra o no hay kárdex con qué comparar
     */
    public function handle(Term $term, Modality $modality, ?Campus $campus, ?User $by, bool $sync = false): ForecastEvaluation
    {
        $this->ensureEvaluable($term, $modality, $campus);

        $evaluation = DB::transaction(function () use ($term, $modality, $campus, $by): ForecastEvaluation {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['forecast-evaluation:'.RunForecastEvaluation::scopeKey($term->getKey(), $modality->getKey(), $campus?->getKey())]);

            $current = ForecastEvaluation::query()
                ->where('term_id', $term->getKey())
                ->where('modality_id', $modality->getKey())
                ->when($campus === null, fn ($query) => $query->whereNull('campus_id'), fn ($query) => $query->where('campus_id', $campus->getKey()))
                ->whereIn('status', [ForecastEvaluationStatus::Queued, ForecastEvaluationStatus::Running])
                ->lockForUpdate()
                ->first();

            if ($current !== null && $current->updated_at->gte(now()->subSeconds(RunForecastEvaluation::STALE_AFTER))) {
                return $current;
            }

            $current?->forceFill([
                'status' => ForecastEvaluationStatus::Failed,
                'finished_at' => now(),
                'error' => 'No terminó en una hora: se pidió otra evaluación.',
            ])->save();

            $evaluation = ForecastEvaluation::query()->create([
                'term_id' => $term->getKey(),
                'modality_id' => $modality->getKey(),
                'campus_id' => $campus?->getKey(),
                'status' => ForecastEvaluationStatus::Queued,
                'method' => DemandForecaster::VERSION,
                'history_terms' => DemandSettings::fromSettings()->historyTerms,
                'requested_by' => $by?->getKey(),
                'progress' => 0,
            ]);

            activity()
                ->performedOn($evaluation)
                ->causedBy($by)
                ->event('requested')
                ->withChanges(['attributes' => [
                    'term_id' => $evaluation->term_id,
                    'modality_id' => $evaluation->modality_id,
                    'campus_id' => $evaluation->campus_id,
                    'status' => $evaluation->status->value,
                    'method' => $evaluation->method,
                    'history_terms' => $evaluation->history_terms,
                ]])
                ->log("Pidió evaluar la proyección de {$term->name} en {$modality->name}".($campus === null ? '' : " ({$campus->name})"));

            return $evaluation;
        });

        if (! $evaluation->wasRecentlyCreated) {
            return $evaluation;
        }

        $job = new RunForecastEvaluation($evaluation->getKey());

        if ($sync) {
            Bus::dispatchSync($job);
        } else {
            dispatch($job)->afterCommit();
        }

        return $evaluation->refresh();
    }

    /**
     * DM-28: lo que impide evaluar, en el orden en que se revisa.
     *
     * @throws ValidationException
     */
    private function ensureEvaluable(Term $term, Modality $modality, ?Campus $campus): void
    {
        if (! TermCode::isRegular($term->code)) {
            throw ValidationException::withMessages(['term' => 'La proyección no calcula cursos de verano: elige un periodo Ene-Jun o Ago-Dic.']);
        }

        if ($campus !== null && $modality->valid_profile?->usesRooms !== true) {
            throw ValidationException::withMessages(['campus' => "{$modality->name} no usa salones: se evalúa con todos sus campus."]);
        }

        $counted = KardexRecord::query()
            ->join('students', 'students.id', '=', 'kardex_records.student_id')
            ->whereNull('students.deleted_at')
            ->where('kardex_records.term_code', $term->code)
            ->whereIn('kardex_records.result', [KardexResult::Aprobada, KardexResult::Reprobada, KardexResult::Cursando])
            ->where('students.modality_id', $modality->getKey())
            ->when($campus !== null, fn ($query) => $query->where('students.campus_id', $campus->getKey()))
            ->exists();

        if (! $counted) {
            $where = $campus === null ? '' : " en {$campus->name}";

            throw ValidationException::withMessages(['term' => "No hay kárdex de {$term->name}{$where}: no hay con qué comparar."]);
        }
    }
}
