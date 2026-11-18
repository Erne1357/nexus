<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Demand\Rules\TermCode;
use App\Modules\Imports\Enums\BatchStatus;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Models\KardexRecord;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Si el re-análisis de una carga publicada está habilitado (RA-02, fase 8; tarea 8.1). Es una consulta sobre lo que hay
 * hoy y no una bandera guardada: el jefe la pregunta al iniciar (StartReanalysis la usa de guarda) y el aviso «ya puedes
 * re-analizar» la usa para saber a quién avisar. Lo que habilita el re-análisis son las calificaciones del semestre
 * actual (confirmado el 17 de noviembre de 2026), y se comprueban cuatro cosas, cada una con su motivo:
 *
 * 1. La carga está publicada.
 * 2. Un lote de kárdex se aplicó después de su última publicación (`published_at`): es la referencia contra la que se
 *    compara la frescura de la proyección.
 * 3. Llegaron calificaciones nuevas del semestre ordinario anterior al de la carga (TermCode::previousRegular: la carga
 *    de `20273` se re-analiza con las de `20271`, DM-01): hay al menos una fila aprobada o reprobada de ese periodo entre
 *    los alumnos activos de la modalidad cuyo `updated_at` es posterior a `published_at`. Una reimportación sin
 *    calificaciones nuevas no basta: la importación solo toca `updated_at` de lo que cambió. No se exige que todos estén
 *    calificados: «el jefe decide, viendo el avance» (confirmado el 17 de noviembre de 2026), y `progress()` le dice
 *    cuántas van (N de M, %). Si llegan más calificaciones después, la carga vuelve a publicarse y puede re-analizarse
 *    otra vez.
 * 4. La proyección de demanda del periodo de la carga y su modalidad se recalculó después de ese lote: todas sus filas
 *    tienen `computed_at` posterior a la aplicación del último lote de kárdex. La cadena que recalcula es asíncrona
 *    (lote, avance de alumnos, proyección), por eso sin esto el jefe compararía contra una proyección vieja.
 *
 * El aviso sale de DemandForecastsComputed, el momento en que esta consulta se vuelve verdadera, no del lote.
 */
final class ReanalysisAvailabilityQuery
{
    private const string KARDEX_TEMPLATE = 'kardex';

    private const array GRADED = [KardexResult::Aprobada, KardexResult::Reprobada];

    /**
     * Por qué no está habilitado, en palabras; vacío si lo está.
     *
     * @return list<string>
     */
    public function reasons(AcademicLoad $load): array
    {
        return array_values($this->blockers($load));
    }

    /**
     * Lo mismo que reasons(), por condición, para que la pantalla del re-análisis marque cada una (RA-02): `load` (la
     * carga no está publicada o no tiene fecha de publicación; entonces no se revisa lo demás), `kardex` (sin lote de
     * kárdex después de la publicación; tampoco se revisa lo demás), `grades` y `forecast`. Solo las que faltan.
     *
     * @return array<'load'|'kardex'|'grades'|'forecast', string>
     */
    public function blockers(AcademicLoad $load): array
    {
        return $this->evaluate($load, fn (): ?string => $this->oldestForecast($load));
    }

    /**
     * @param  Closure(): ?string  $oldestForecast  el `computed_at` más viejo de la proyección del periodo y la modalidad
     * @return array<'load'|'kardex'|'grades'|'forecast', string>
     */
    private function evaluate(AcademicLoad $load, Closure $oldestForecast): array
    {
        $load->loadMissing(['term', 'modality']);

        if ($load->status !== LoadStatus::Published) {
            return ['load' => "La carga está en {$load->status->label()}: solo una carga publicada pasa a re-análisis."];
        }

        if ($load->published_at === null) {
            return ['load' => 'La carga no tiene fecha de publicación: no se puede saber si llegaron calificaciones nuevas.'];
        }

        $batch = $this->lastKardexBatchAfter($load->published_at);

        if ($batch === null) {
            return ['kardex' => "No se ha importado un kárdex después de la versión {$load->current_version} de la carga."];
        }

        $reasons = [];
        $previous = TermCode::previousRegular($load->term->code);
        $graded = $this->kardexRows($load, $previous)->whereIn('kardex_records.result', self::GRADED);

        if (! (clone $graded)->where('kardex_records.updated_at', '>', $load->published_at)->exists()) {
            $reasons['grades'] = $graded->exists()
                ? "No hay calificaciones nuevas de {$previous} en {$load->modality->name} después de la versión {$load->current_version} de la carga: importa las del semestre actual."
                : "No hay calificaciones de {$previous} en {$load->modality->name}: importa las del semestre actual.";
        }

        $oldest = $oldestForecast();

        if ($oldest === null) {
            $reasons['forecast'] = "La proyección de demanda de {$load->term->code} en {$load->modality->name} no se ha calculado.";
        } elseif ((new CarbonImmutable($oldest))->lessThanOrEqualTo($batch)) {
            $reasons['forecast'] = 'La proyección de demanda todavía no se recalcula con el kárdex importado el '.$batch->format('d/m/Y H:i').'.';
        }

        return $reasons;
    }

    private function oldestForecast(AcademicLoad $load): ?string
    {
        return DemandForecast::query()
            ->where('term_id', $load->term_id)->where('modality_id', $load->modality_id)
            ->min('computed_at');
    }

    /**
     * Cuántas calificaciones del semestre ordinario anterior van capturadas entre los alumnos activos de la modalidad:
     * `graded` son las filas con resultado final (aprobada o reprobada), `total` esas más las que siguen `cursando`
     * y `percent` el avance redondeado hacia abajo (0 si no hay filas). Es lo que el jefe ve para decidir cuándo iniciar.
     *
     * @return array{graded: int, total: int, percent: int}
     */
    public function progress(AcademicLoad $load): array
    {
        $load->loadMissing('term');
        $previous = TermCode::previousRegular($load->term->code);

        $graded = $this->kardexRows($load, $previous)->whereIn('kardex_records.result', self::GRADED)->count();
        $inProgress = $this->kardexRows($load, $previous)->where('kardex_records.result', KardexResult::Cursando)->count();
        $total = $graded + $inProgress;

        return ['graded' => $graded, 'total' => $total, 'percent' => $total === 0 ? 0 : intdiv($graded * 100, $total)];
    }

    public function isAvailable(AcademicLoad $load): bool
    {
        return $this->reasons($load) === [];
    }

    /**
     * Las cargas publicadas del periodo y la modalidad con el re-análisis habilitado.
     *
     * @return Collection<int, AcademicLoad>
     */
    public function availableLoads(string $termId, string $modalityId): Collection
    {
        $loads = AcademicLoad::query()
            ->where('term_id', $termId)->where('modality_id', $modalityId)
            ->where('status', LoadStatus::Published)
            ->with(['term', 'modality', 'department'])
            ->get();

        if ($loads->isEmpty()) {
            return $loads;
        }

        // Todas comparten periodo y modalidad: la proyección se lee una vez, no por carga.
        $oldest = $this->oldestForecast($loads->first());
        $forecast = fn (): ?string => $oldest;

        return $loads->filter(fn (AcademicLoad $load): bool => $this->evaluate($load, $forecast) === [])->values();
    }

    /**
     * Cuándo se aplicó el último lote de kárdex posterior a `$after`, o null si no hubo.
     */
    public function lastKardexBatchAfter(CarbonInterface $after): ?CarbonImmutable
    {
        $appliedAt = ImportBatch::query()
            ->where('template', self::KARDEX_TEMPLATE)->where('status', BatchStatus::Applied)
            ->where('applied_at', '>', $after)
            ->max('applied_at');

        return $appliedAt === null ? null : new CarbonImmutable($appliedAt);
    }

    /**
     * Las filas contables del kárdex de un periodo de los alumnos activos de la modalidad de la carga.
     *
     * @return Builder<KardexRecord>
     */
    private function kardexRows(AcademicLoad $load, string $termCode): Builder
    {
        return KardexRecord::query()
            ->join('students', 'students.id', '=', 'kardex_records.student_id')
            ->whereNull('students.deleted_at')
            ->where('students.is_active', true)
            ->where('students.status', StudentStatus::Activo)
            ->where('students.modality_id', $load->modality_id)
            ->where('kardex_records.term_code', $termCode);
    }
}
