<?php

namespace App\Modules\AcademicLoad\Database\Seeders;

use App\Modules\AcademicLoad\Actions\Transitions\CancelReanalysis;
use App\Modules\AcademicLoad\Actions\Transitions\StartReanalysis;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\LoadTransitioning;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Demo\DemoClock;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Demand\Database\Seeders\DemandRecalculationDemoSeeder;
use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Demand\Rules\TermCode;
use App\Modules\Imports\Enums\BatchStatus;
use App\Modules\Imports\Events\BatchApplied;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Students\Actions\RefreshStudentProgress;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Models\KardexRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Demo del re-análisis (fase 8, #614): lleva al punto `reanalisis-casos` las cargas que el punto 9 dejó publicadas en su
 * versión 1, cada una a un caso del re-análisis, para que la pantalla del re-análisis, el tablero de versiones y la campana
 * se vean llenos. Todo por las Actions reales, como el jefe de cada departamento (`jefe.<clave>`; el admin si no lo hay) y
 * Subdirección (`subdir`). El reparto es `plan()`: por clave de departamento, en el orden de CASES y, si sobran cargas,
 * otra vuelta con EXTRA_CASES. Con el volumen demo hay 4 cargas: CB corregida en v3, CEA con cambios, II cancelada y vuelta
 * a iniciar, SC habilitada; con el masivo 6: además MM republicada en v2 y SC cerrada (II habilitada, EE cancelada y vuelta
 * a iniciar). Hay una carga por departamento y modalidad (M1), así que más casos por pantalla salen de cada carga, no de
 * más cargas.
 *
 * Este seeder hace la parte de AcademicLoad, en tres modos:
 *
 * - `grades`: las calificaciones del semestre ordinario anterior (TermCode::previousRegular, las de `20263` para la carga
 *   de `20271`) capturadas en materias completas hasta GRADED_SHARE (DemandRecalculationDemoSeeder, con FAILURE_SHARE de
 *   reprobadas), un lote de kárdex aplicado como lo deja ApplyImportBatch y, un segundo después, el avance de los alumnos,
 *   que recalcula la proyección por la cadena real (StudentProgressRefreshed → ComputeDemandForecasts →
 *   DemandForecastsComputed) y avisa a los jefes que ya pueden re-analizar (ReanalysisAvailable). Las fechas se guardan al
 *   segundo y RA-02 compara con «después de» estricto, por eso espera al segundo siguiente (DemoClock) antes del lote y
 *   antes de recalcular.
 * - `start`: el jefe inicia el re-análisis de cada carga cuyo caso no es «habilitada» (StartReanalysis); la de «cancelada y
 *   vuelta a iniciar» lo cancela sin cambios (CancelReanalysis, RA-10) y lo vuelve a iniciar.
 * - `reimport`: un segundo lote de kárdex sin calificaciones nuevas (una reimportación) y la proyección recalculada otra
 *   vez. Lo pide el paso de Staffing después de republicar las versiones 2, solo si el plan tiene una carga «republicada»:
 *   así esa carga muestra «Sin calificaciones nuevas» y la corregida, publicada después del lote, «Sin kárdex nuevo».
 *
 * Los cambios de la carga en re-análisis los hace Scheduling (ReanalysisChangesDemoSeeder) y el cambio de docente, los
 * cierres, la devolución de Subdirección, las republicaciones y la corrección, Staffing (ReanalysisRepublicationDemoSeeder):
 * AcademicLoad no puede llamar a sus Actions. Cada modo es repetible: revisa el estado de cada carga y los lotes ya
 * registrados antes de escribir, así una segunda corrida no duplica lotes, transiciones ni versiones, y una que se cayó a
 * la mitad sigue desde donde se quedó (un lote sin la proyección recalculada se recalcula; una cancelación sin el segundo
 * inicio, lo inicia).
 */
final class ReanalysisDemoSeeder extends Seeder
{
    use RefusesProduction;

    /** Publicada en su versión 1, con el re-análisis habilitado y sin iniciar: su jefe tiene el aviso. */
    public const string CASE_AVAILABLE = 'available';

    /** En re-análisis, sin cambios: se puede cancelar. */
    public const string CASE_UNCHANGED = 'unchanged';

    /** Canceló el re-análisis sin cambios (RA-10) y lo volvió a iniciar; sigue sin cambios. */
    public const string CASE_RESTARTED = 'restarted';

    /** En re-análisis con cambios: la cerró, Subdirección la devolvió a re-análisis (RA-08) y ya no se cancela. */
    public const string CASE_CHANGED = 'changed';

    /** Re-analizada con cambios y cerrada, por publicar su versión 2 (la modalidad no exige visto bueno). */
    public const string CASE_CLOSED = 'closed';

    /** Republicada en su versión 2 por re-análisis, con su diff y sus avisos. */
    public const string CASE_REPUBLISHED = 'republished';

    /** Versión 2 por re-análisis y versión 3 por corrección (reabierta a ajustes por Subdirección). */
    public const string CASE_CORRECTED = 'corrected';

    /**
     * Los casos en el orden en que se reparten: primero los que llenan más pantallas, para que la demo chica (4 cargas)
     * tenga la línea de versiones con los tres motivos, una carga con cambios, una cancelada y una habilitada.
     */
    public const array CASES = [self::CASE_CORRECTED, self::CASE_CHANGED, self::CASE_RESTARTED, self::CASE_AVAILABLE, self::CASE_REPUBLISHED, self::CASE_CLOSED, self::CASE_UNCHANGED];

    /** La vuelta para las cargas que sobran después de CASES. */
    public const array EXTRA_CASES = [self::CASE_AVAILABLE, self::CASE_CHANGED, self::CASE_REPUBLISHED, self::CASE_UNCHANGED, self::CASE_CLOSED, self::CASE_RESTARTED, self::CASE_CORRECTED];

    /** Los casos cuya carga cambia en el re-análisis (Scheduling y Staffing). */
    public const array CHANGING = [self::CASE_CHANGED, self::CASE_CLOSED, self::CASE_REPUBLISHED, self::CASE_CORRECTED];

    /** La parte de lo que se cursa que queda calificada: el avance «N de M» del jefe. */
    public const float GRADED_SHARE = 0.85;

    /** La parte de lo calificado que sale reprobada. */
    public const float FAILURE_SHARE = 0.15;

    /**
     * El caso de cada carga del periodo y la modalidad que salió de borrador (las que el punto 9 publicó), por id: por
     * clave de departamento, en el orden de CASES y luego de EXTRA_CASES. La «corregida» vuelve a asignación
     * (ReturnToStaffing): si a la carga que le toca un módulo de la derecha le vetaría esa vuelta (solicitudes de cambio
     * aprobadas sin aplicar, choques duros; returnVetoes()), cambia su caso con la siguiente carga que sí puede volver.
     * Depende de qué cargas tienen grupos y de esos vetos, que la demo no cambia, no del estado de la carga, así que los
     * pasos y las revisiones lo calculan igual antes y después de sembrar.
     *
     * @return array<string, string>
     */
    public static function plan(string $termId, string $modalityId): array
    {
        $loads = AcademicLoad::query()
            ->with(['department', 'modality'])
            ->where('term_id', $termId)->where('modality_id', $modalityId)
            ->where('status', '!=', LoadStatus::Draft->value)
            ->get()
            ->sortBy(fn (AcademicLoad $load): string => (string) $load->department?->code)
            ->values();

        $cases = $loads->keys()->map(fn (int $index): string => self::CASES[$index] ?? self::EXTRA_CASES[($index - count(self::CASES)) % count(self::EXTRA_CASES)])->all();

        foreach ($cases as $index => $case) {
            if ($case !== self::CASE_CORRECTED || self::returnVetoes($loads[$index]) === []) {
                continue;
            }

            for ($other = $index + 1; $other < count($cases); $other++) {
                if ($cases[$other] !== self::CASE_CORRECTED && self::returnVetoes($loads[$other]) === []) {
                    [$cases[$index], $cases[$other]] = [$cases[$other], $cases[$index]];

                    break;
                }
            }
        }

        $plan = [];

        foreach ($loads as $index => $load) {
            $plan[$load->getKey()] = $cases[$index];
        }

        return $plan;
    }

    /**
     * Lo que vetaría que la carga volviera de ajustes a asignación (ReturnToStaffing), sin moverla: pregunta a los módulos
     * de la derecha con el mismo evento con veto que TransitionLoad (LoadTransitioning). Solo lee.
     *
     * @return list<string>
     */
    public static function returnVetoes(AcademicLoad $load): array
    {
        $event = new LoadTransitioning($load, LoadStatus::Adjusting, LoadStatus::Staffing);
        event($event);

        return $event->reasons();
    }

    /**
     * Las cargas del plan con alguno de los casos dados, por clave de departamento.
     *
     * @param  list<string>  $cases
     * @return Collection<int, AcademicLoad>
     */
    public static function loadsIn(string $termId, string $modalityId, array $cases): Collection
    {
        $ids = array_keys(array_filter(self::plan($termId, $modalityId), fn (string $case): bool => in_array($case, $cases, true)));

        return AcademicLoad::query()->with(['department', 'modality', 'term'])->whereKey($ids)->get()
            ->sortBy(fn (AcademicLoad $load): string => (string) $load->department?->code)
            ->values();
    }

    /**
     * El jefe del departamento de la carga, como en la publicación de la demo (`jefe.<clave>`); el admin si no lo hay.
     */
    public static function headOf(AcademicLoad $load): User
    {
        $load->loadMissing('department');

        return User::query()->where('username', 'jefe.'.Str::lower((string) $load->department?->code))->first()
            ?? User::query()->where('username', 'admin')->firstOrFail();
    }

    /**
     * Subdirección de la demo (`subdir`); el admin si no la hay.
     */
    public static function subdireccion(): User
    {
        return User::query()->where('username', 'subdir')->first() ?? User::query()->where('username', 'admin')->firstOrFail();
    }

    /**
     * El nombre del archivo del lote de kárdex de la demo (`calificaciones-20263.csv`, `kardex-20263-reimportado.csv`):
     * es como el modo sabe si ya lo registró.
     */
    public static function batchName(string $previousTerm, bool $reimport = false): string
    {
        return $reimport ? "kardex-{$previousTerm}-reimportado.csv" : "calificaciones-{$previousTerm}.csv";
    }

    /**
     * @param  'grades'|'start'|'reimport'  $mode
     */
    public function run(string $mode = 'grades', string $term = '20271', string $modality = 'PRES', int $seed = 2027): void
    {
        $this->refuseProduction();

        $termModel = Term::query()->where('code', $term)->first() ?? throw new RuntimeException("El periodo {$term} no existe.");
        $modalityModel = Modality::query()->where('code', $modality)->firstOrFail();

        match ($mode) {
            'grades' => $this->grades($termModel, $seed),
            'start' => $this->start($termModel, $modalityModel),
            'reimport' => $this->reimport($termModel, $modalityModel),
            default => throw new InvalidArgumentException("Modo desconocido de la demo del re-análisis: {$mode}."),
        };
    }

    /**
     * Las calificaciones, el lote y la proyección. Se retoma desde donde se quedó una corrida que se cayó: no vuelve a
     * calificar lo capturado (subjectsToCapture), no repite el lote y recalcula si la proyección es de antes del lote.
     */
    private function grades(Term $term, int $seed): void
    {
        $previous = TermCode::previousRegular($term->code);
        $name = self::batchName($previous);
        $batch = $this->batch($name);

        if ($batch === null) {
            // Después del segundo de la publicación: el lote y las calificaciones tienen que ser posteriores (RA-02).
            DemoClock::nextSecond();
            $published = AcademicLoad::query()->where('term_id', $term->getKey())->max('published_at');
            $this->callWith(DemandRecalculationDemoSeeder::class, [
                'term' => $term->code,
                'failureShare' => self::FAILURE_SHARE,
                'seed' => $seed,
                'capturedShare' => self::GRADED_SHARE,
                'refresh' => false,
            ]);
            $graded = $this->gradedRows($previous)->when($published !== null, fn (Builder $rows) => $rows->where('updated_at', '>', $published))->count();
            $batch = $this->recordBatch($name, $graded, KardexRecord::query()->where('term_code', $previous)->count());
        }

        if ($this->forecastsAfter($term, $batch)) {
            $this->command?->info("Las calificaciones de {$previous} ya están importadas ({$name}) y la proyección es posterior.");

            return;
        }

        $this->refreshForecasts();
        $this->command?->info("Lote de kárdex {$name} con {$batch->updated_rows} calificaciones nuevas; la proyección de {$term->code} se recalculó y los jefes tienen el aviso.");
    }

    /**
     * El jefe inicia el re-análisis; la «cancelada y vuelta a iniciar» lo cancela y lo vuelve a iniciar. Cada paso mira el
     * estado y el historial de la carga, así que una corrida que se cayó a la mitad sigue desde ahí.
     */
    private function start(Term $term, Modality $modality): void
    {
        $gradesTerm = Term::query()->where('code', TermCode::previousRegular($term->code))->value('name') ?? TermCode::previousRegular($term->code);
        $plan = self::plan($term->getKey(), $modality->getKey());
        $started = [];

        foreach (self::loadsIn($term->getKey(), $modality->getKey(), array_values(array_diff(self::CASES, [self::CASE_AVAILABLE]))) as $load) {
            $case = $plan[$load->getKey()];
            $head = self::headOf($load);
            $starts = fn (): int => $load->transitions()->where('to_status', LoadStatus::Reanalysis->value)->count();
            $cancelled = fn (): bool => $load->transitions()->where('comment', CancelReanalysis::COMMENT)->exists();
            $before = $starts();

            if ($load->status === LoadStatus::Published && $load->current_version === 1 && $before === 0) {
                app(StartReanalysis::class)->handle($load, $head, "Llegaron las calificaciones de {$gradesTerm}: reviso grupos y cupos contra la demanda nueva.");
            }

            if ($case === self::CASE_RESTARTED) {
                if ($load->fresh()->status === LoadStatus::Reanalysis && ! $cancelled()) {
                    app(CancelReanalysis::class)->handle($load->fresh(), $head);
                }

                if ($load->fresh()->status === LoadStatus::Published && $cancelled() && $starts() === 1) {
                    app(StartReanalysis::class)->handle($load->fresh(), $head, 'Lo vuelvo a iniciar para revisar también los grupos compartidos con otras carreras.');
                }
            }

            if ($starts() > $before) {
                $started[] = $load->department->code.($case === self::CASE_RESTARTED ? ' (cancelado y vuelto a iniciar)' : '');
            }
        }

        $this->command?->info('Re-análisis iniciados: '.($started === [] ? 'ninguno nuevo' : implode(', ', $started)).'.');
    }

    /**
     * La reimportación sin calificaciones nuevas, solo si el plan tiene una «republicada». Como `grades`, se retoma: no
     * repite el lote y recalcula si la proyección es de antes de él.
     */
    private function reimport(Term $term, Modality $modality): void
    {
        $previous = TermCode::previousRegular($term->code);
        $name = self::batchName($previous, reimport: true);

        if (! in_array(self::CASE_REPUBLISHED, self::plan($term->getKey(), $modality->getKey()), true)) {
            return;
        }

        $batch = $this->batch($name);

        if ($batch === null) {
            // Después del segundo de las versiones 2: el lote es posterior a ellas, pero no trae calificaciones nuevas.
            DemoClock::nextSecond();
            $batch = $this->recordBatch($name, 0, KardexRecord::query()->where('term_code', $previous)->count());
        }

        if ($this->forecastsAfter($term, $batch)) {
            return;
        }

        $this->refreshForecasts();
        $this->command?->info("Reimportación del kárdex de {$previous} sin calificaciones nuevas ({$name}); la proyección se recalculó otra vez.");
    }

    /**
     * El lote como lo deja ApplyImportBatch al aplicar un kárdex: aplicado, con sus cuentas, una entrada de bitácora y
     * BatchApplied después del commit. Es solo de la demo y no pasa por Imports (StoreImportBatch, ValidateImportBatch,
     * ApplyImportBatch) porque esa cadena lee un archivo con los datos de cada alumno y lo escribe fila por fila: con el
     * masivo serían unas 45 mil filas de kárdex, y el archivo (con datos personales, aunque sean inventados) quedaría en el
     * disco `imports`. La demo escribe el kárdex directo, como DemandRecalculationDemoSeeder; ninguna otra demo registra
     * lotes. Lo que lee RA-02 del lote es la plantilla, el estado y `applied_at`.
     */
    private function recordBatch(string $name, int $updated, int $rows): ImportBatch
    {
        $admin = User::query()->where('username', 'admin')->firstOrFail();

        return DB::transaction(function () use ($admin, $name, $updated, $rows): ImportBatch {
            $batch = new ImportBatch([
                'template' => 'kardex',
                'original_name' => $name,
                'status' => BatchStatus::Applied,
                'total_rows' => $rows,
                'valid_rows' => $rows,
                'processed_rows' => $rows,
                'updated_rows' => $updated,
                'unchanged_rows' => $rows - $updated,
                'uploaded_by' => $admin->getKey(),
                'applied_by' => $admin->getKey(),
                'applied_at' => now(),
            ]);
            $batch->id = $batch->newUniqueId();
            $batch->file_path = "{$batch->id}.csv";
            $batch->save();

            $counts = ['created' => 0, 'updated' => $updated, 'unchanged' => $rows - $updated, 'skipped' => 0, 'deactivated' => 0];

            activity()
                ->performedOn($batch)
                ->causedBy($admin)
                ->event('applied')
                ->withProperties($counts)
                ->log("Aplicó {$name} de Kárdex: {$updated} cambios y ".($rows - $updated).' sin cambios');

            BatchApplied::dispatch($batch, $counts);

            return $batch;
        });
    }

    /**
     * Un segundo después del lote, el avance de los alumnos, como RecomputeStudentProgress al terminar la importación:
     * avisa con StudentProgressRefreshed y Demand recalcula la proyección de los periodos abiertos (cola síncrona).
     */
    private function refreshForecasts(): void
    {
        DemoClock::nextSecond();
        app(RefreshStudentProgress::class)->handle();
    }

    private function batch(string $name): ?ImportBatch
    {
        return ImportBatch::query()->where('template', 'kardex')->where('status', BatchStatus::Applied->value)->where('original_name', $name)->first();
    }

    /**
     * Si toda la proyección del periodo se calculó después del lote (la condición 4 de RA-02): si no, falta recalcular.
     */
    private function forecastsAfter(Term $term, ImportBatch $batch): bool
    {
        $oldest = DemandForecast::query()->where('term_id', $term->getKey())->min('computed_at');

        return $oldest !== null && $batch->applied_at !== null && (new CarbonImmutable($oldest))->greaterThan($batch->applied_at);
    }

    /**
     * @return Builder<KardexRecord>
     */
    private function gradedRows(string $termCode): Builder
    {
        return KardexRecord::query()->where('term_code', $termCode)->whereIn('result', [KardexResult::Aprobada, KardexResult::Reprobada]);
    }
}
