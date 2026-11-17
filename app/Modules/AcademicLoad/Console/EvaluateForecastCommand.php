<?php

namespace App\Modules\AcademicLoad\Console;

use App\Modules\Academic\Models\Career;
use App\Modules\AcademicLoad\Actions\RequestForecastEvaluation;
use App\Modules\AcademicLoad\Enums\ForecastConfidence;
use App\Modules\AcademicLoad\Enums\ForecastEvaluationStatus;
use App\Modules\AcademicLoad\Models\ForecastEvaluation;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Models\KardexRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Evalúa la proyección de demanda de un periodo ya cursado (tarea 5.9b; RequestForecastEvaluation, DM-27 a DM-29): por
 * omisión la corre en este proceso e imprime, por carrera y campus, el error ponderado, el de R1 y la confianza (DM-22),
 * y la línea global; con `--queue` solo la encola, como la pantalla. La pide sin usuario: nadie recibe el aviso.
 *
 * Sin `--modality` toma la única modalidad activa con kárdex del periodo, sin mirar su clave (CLAUDE.md, arquitectura
 * 8); si hay varias, pide que se elija una.
 */
final class EvaluateForecastCommand extends Command
{
    protected $signature = 'academic-load:evaluate-forecast
        {term : Código SII del periodo (p. ej. 20261)}
        {--modality= : Clave de la modalidad; por omisión, la única activa con kárdex del periodo}
        {--campus= : Código del campus; por omisión, todos}
        {--queue : Solo encolar la evaluación}';

    protected $description = 'Evalúa la proyección de demanda de un periodo ya cursado contra lo que se cursó';

    public function handle(RequestForecastEvaluation $request): int
    {
        $term = Term::query()->where('code', $this->argument('term'))->first();

        if ($term === null) {
            return $this->refuse("No existe el periodo {$this->argument('term')}.");
        }

        $modality = $this->modality($term);

        if (is_string($modality)) {
            return $this->refuse($modality);
        }

        $campus = null;

        if ($this->option('campus') !== null && ($campus = Campus::query()->where('code', $this->option('campus'))->first()) === null) {
            return $this->refuse("No existe el campus {$this->option('campus')}.");
        }

        try {
            $evaluation = $request->handle($term, $modality, $campus, null, sync: ! $this->option('queue'));
        } catch (ValidationException $exception) {
            return $this->refuse($exception->validator->errors()->first());
        } catch (Throwable $exception) {
            return $this->refuse("La evaluación no terminó: {$exception->getMessage()}");
        }

        if ($evaluation->status !== ForecastEvaluationStatus::Done) {
            $this->components->info($evaluation->wasRecentlyCreated
                ? "Evaluación de {$term->name} en cola."
                : "Ya hay una evaluación de {$term->name} en curso ({$evaluation->status->label()}).");

            return self::SUCCESS;
        }

        $this->report($evaluation);

        return self::SUCCESS;
    }

    /**
     * La modalidad de `--modality`, o la única activa con kárdex contable del periodo; si no se puede, el motivo.
     */
    private function modality(Term $term): Modality|string
    {
        $code = $this->option('modality');

        if ($code !== null) {
            return Modality::query()->where('code', $code)->first() ?? "No existe la modalidad {$code}.";
        }

        $withKardex = Modality::query()
            ->where('is_active', true)
            ->whereIn('id', KardexRecord::query()
                ->join('students', 'students.id', '=', 'kardex_records.student_id')
                ->whereNull('students.deleted_at')
                ->where('kardex_records.term_code', $term->code)
                ->whereIn('kardex_records.result', [KardexResult::Aprobada, KardexResult::Reprobada, KardexResult::Cursando])
                ->select('students.modality_id'))
            ->get();

        return match ($withKardex->count()) {
            0 => "No hay kárdex de {$term->name}: no hay con qué comparar.",
            1 => $withKardex->first(),
            default => "Hay varias modalidades activas con kárdex de {$term->name}: elige una con --modality.",
        };
    }

    private function report(ForecastEvaluation $evaluation): void
    {
        $totals = $evaluation->totals;
        $careers = Career::withTrashed()->whereIn('id', array_column($totals['by_career'], 'career_id'))->pluck('code', 'id');
        $campuses = Campus::withTrashed()->pluck('code', 'id');
        $lines = new Collection;

        foreach ($totals['by_career'] as $career) {
            foreach ($career['by_campus'] as $column) {
                $lines->push($this->tableRow($careers[$career['career_id']] ?? $career['career_id'], $column['campus_id'] === null ? 'Sin campus' : ($campuses[$column['campus_id']] ?? $column['campus_id']), $column));
            }

            if (count($career['by_campus']) > 1) {
                $lines->push($this->tableRow($careers[$career['career_id']] ?? $career['career_id'], 'Todos', $career));
            }
        }

        $this->table(['Carrera', 'Campus', 'Error ponderado', 'Error R1', 'Confianza'], $lines->all());

        $overall = $totals['overall'];
        $this->components->info("Global: error ponderado {$this->percent($overall['weighted_error'])}, error R1 {$this->percent($overall['baseline_error'])}, {$this->confidence($overall['confidence'])}.");
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return list<string>
     */
    private function tableRow(string $career, string $campus, array $metrics): array
    {
        return [$career, $campus, $this->percent($metrics['weighted_error']), $this->percent($metrics['baseline_error']), $this->confidence($metrics['confidence'])];
    }

    private function percent(int|float|null $value): string
    {
        return $value === null ? '—' : number_format($value * 100, 1).' %';
    }

    private function confidence(string $value): string
    {
        return (ForecastConfidence::tryFrom($value) ?? ForecastConfidence::Unmeasured)->label();
    }

    private function refuse(string $message): int
    {
        $this->components->error($message);

        return self::FAILURE;
    }
}
