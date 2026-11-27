<?php

namespace App\Modules\Imports\Sii;

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Actions\ApplyImportBatch;
use App\Modules\Imports\Actions\StoreImportBatch;
use App\Modules\Imports\Actions\ValidateImportBatch;
use App\Modules\Imports\Enums\BatchStatus;
use App\Modules\Imports\Enums\RowStatus;
use App\Modules\Imports\ImportsServiceProvider;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Templates\TemplateRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Corre los pasos de la migración del SII en orden: cada lote que entrega un paso se escribe como CSV
 * intermedio en `<dir>/_nexus/` (encabezados = etiquetas de la plantilla, que ColumnMatcher reconoce) y pasa
 * por el motor de importación (StoreImportBatch, ValidateImportBatch, ApplyImportBatch), así que valen las
 * mismas reglas y la misma bitácora que en el asistente. Lo que valida la plantilla y lo que descarta un paso
 * queda en `<dir>/_incidencias.csv`, sin filas crudas; también, una vez por archivo del SII, los renglones que no se
 * pudieron leer (error: no se migran), los reparados por coma decimal y la anotación al final (`SiiFileRead`).
 *
 * El primer paso, `archivo` (ArchiveStep), no pasa por el motor: guarda la exportación original en el esquema
 * `sii_archivo` y entrega directamente su reporte por archivo (SiiStep). Como lee completo cada archivo, las
 * incidencias de lectura de toda la exportación quedan a su nombre cuando corre. El último, `verificacion`
 * (VerificationStep), tampoco: compara el archivo con Nexus y entrega sus reportes con sus incidencias.
 *
 * Con `$dryRun` todo corre dentro de una transacción que se revierte, sin cola, y se borran los archivos
 * subidos; las incidencias se leen antes de revertir. Sin `$dryRun` los archivos subidos se conservan como
 * evidencia de cada lote.
 *
 * Un campus o plantilla desconocidos se detectan al llegar a su lote, antes de guardarlo: los lotes anteriores
 * ya se aplicaron en una corrida real (son altas por clave natural, repetirlas no cambia nada) y se revierten en
 * un ensayo. Cualquier fallo se reporta como SiiMigrationStopped, sin valores. `_incidencias.csv` se reescribe
 * siempre al terminar; una corrida parcial (`--only`) escribe `_incidencias.<pasos>.csv` y `_nexus.<pasos>/` para no pisar
 * los de la completa. Al log va solo la clase, el lugar y el SQLSTATE de la falla (SiiFailureLog), nunca su mensaje.
 *
 * Los catálogos curados se leen de `Sii/Curated/`; las pruebas pasan otra carpeta como `$curatedDir`.
 *
 * @throws SiiContractBroken antes de escribir nada, si la exportación no cumple el contrato
 * @throws SiiMigrationStopped si un paso o lote falla o la limpieza final falla; el mensaje es fijo y la causa original queda en getPrevious()
 */
final class RunSiiMigration
{
    public function __construct(
        private readonly StoreImportBatch $store,
        private readonly ValidateImportBatch $validate,
        private readonly ApplyImportBatch $apply,
        private readonly TemplateRegistry $templates,
        private readonly string $curatedDir = __DIR__.'/Curated',
    ) {}

    /**
     * El sufijo de los archivos de una corrida parcial: los pasos de `--only`, sin repetir y en orden alfabético,
     * unidos con `+`. Una corrida completa no lleva sufijo.
     *
     * @param  list<string>  $only
     */
    private static function suffix(array $only): string
    {
        $steps = array_values(array_unique($only));
        sort($steps);

        return $steps === [] ? '' : '.'.implode('+', $steps);
    }

    /**
     * Nombre del reporte de incidencias: `_incidencias.csv` en una corrida completa y `_incidencias.<pasos>.csv` en
     * una parcial, para que esta no pise el reporte de la completa.
     *
     * @param  list<string>  $only
     */
    public static function incidentsFileName(array $only): string
    {
        return '_incidencias'.self::suffix($only).'.csv';
    }

    /**
     * Carpeta de los CSV intermedios: `_nexus` en una corrida completa y `_nexus.<pasos>` en una parcial.
     *
     * @param  list<string>  $only
     */
    public static function stagingFolderName(array $only): string
    {
        return '_nexus'.self::suffix($only);
    }

    /**
     * @param  list<string>  $only  nombres de pasos; vacío corre todos
     *
     * @throws SiiContractBroken
     * @throws SiiMigrationStopped
     * @throws InvalidArgumentException si `--only` nombra un paso que no existe
     */
    public function handle(string $dir, User $user, bool $dryRun, array $only = []): SiiMigrationReport
    {
        $export = SiiExport::open($dir);
        $steps = $this->steps();
        $names = array_map(fn (SiiStep $step): string => $step->name(), $steps);
        $unknown = array_values(array_diff($only, $names));

        if ($unknown !== []) {
            throw new InvalidArgumentException('Paso desconocido: '.implode(', ', $unknown).'. Pasos válidos: '.implode(', ', $names).'.');
        }

        $migration = new SiiMigration($export, $this->curatedDir, $dryRun, $user);
        $incidents = new Incidents;
        $unreadable = 0;
        $reports = [];
        $stored = [];
        $queue = config('queue.default');
        $level = DB::transactionLevel();
        $current = [null, null];

        if ($dryRun) {
            config(['queue.default' => 'null']);
            DB::beginTransaction();
        }

        $pending = null;

        try {
            foreach ($steps as $step) {
                if ($only !== [] && ! in_array($step->name(), $only, true)) {
                    continue;
                }

                $current = [$step->name(), null];

                foreach ($step->batches($migration) as $batch) {
                    $current[1] = $batch->file;
                    $reports[] = $batch instanceof SiiBatchReport ? $this->report($step, $batch, $incidents) : $this->run($dir, $user, $step, $batch, $incidents, $stored, $only);
                }

                $unreadable += $this->collectReads($step->name(), $export, $incidents);
            }
        } catch (SiiMigrationStopped $stopped) {
            $pending = new SiiMigrationStopped($stopped->step, $stopped->batchFile, $stopped->cause, count($reports), $stopped->getPrevious());
        } catch (Throwable $exception) {
            SiiFailureLog::write('la corrida se detuvo en el paso '.($current[0] ?? '-'), $exception);
            $pending = new SiiMigrationStopped($current[0] ?? '-', $current[1], $this->causeOf($exception), count($reports), $exception);
        }

        $unreadable += $this->collectReads($current[0] ?? '-', $export, $incidents);
        $cleanup = $this->cleanUp($dir, $dryRun, $queue, $level, $stored, $incidents, $only);

        if ($pending !== null) {
            throw $pending;
        }

        if ($cleanup !== null) {
            throw new SiiMigrationStopped('-', null, 'falló la limpieza ('.class_basename($cleanup).')', count($reports), $cleanup);
        }

        return new SiiMigrationReport($reports, $incidents->count(), $unreadable);
    }

    /**
     * Una incidencia por tipo de lo que dejaron las pasadas completas por los archivos del SII que terminaron en este
     * paso (cada archivo una vez por corrida). Devuelve cuántos renglones no se pudieron leer.
     */
    private function collectReads(string $step, SiiExport $export, Incidents $incidents): int
    {
        $unreadable = 0;

        foreach ($export->takeReads() as $read) {
            $unreadable += $read->unreadable();

            foreach ($read->incidents() as $incident) {
                $incidents->add($step, $read->file, $incident['ref'], '', $incident['level'], $incident['message']);
            }
        }

        return $unreadable;
    }

    /**
     * Revierte el ensayo, borra lo subido y reescribe las incidencias. Cada paso corre aunque otro falle; un
     * fallo va al log (SiiFailureLog: sin mensaje ni valores) y se devuelve el primero, sin tapar un error pendiente.
     *
     * @param  list<string>  $stored
     * @param  list<string>  $only
     */
    private function cleanUp(string $dir, bool $dryRun, mixed $queue, int $level, array $stored, Incidents $incidents, array $only): ?Throwable
    {
        $first = null;
        $steps = [
            fn () => $dryRun ? config(['queue.default' => $queue]) : null,
            fn () => $dryRun ? DB::rollBack($level) : null,
            function () use ($dryRun, $stored): void {
                foreach ($dryRun ? $stored : [] as $path) {
                    Storage::disk('imports')->delete($path);
                }
            },
            fn () => $incidents->write(rtrim($dir, '/').'/'.self::incidentsFileName($only)),
        ];

        foreach ($steps as $step) {
            try {
                $step();
            } catch (Throwable $failure) {
                SiiFailureLog::write('falló la limpieza', $failure);
                $first ??= $failure;
            }
        }

        return $first;
    }

    /**
     * Una causa fija: nunca el mensaje de la excepción, que puede traer valores. Solo SiiCuratedMismatch, que solo nombra
     * claves, y SiiStepOutOfOrder, que solo nombra pasos y mapas.
     */
    private function causeOf(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof SiiCuratedMismatch, $exception instanceof SiiStepOutOfOrder => $exception->getMessage(),
            $exception instanceof InvalidArgumentException => 'campus o plantilla desconocidos',
            default => 'error inesperado ('.class_basename($exception).')',
        };
    }

    /**
     * @return list<SiiStep>
     */
    private function steps(): array
    {
        $classes = app()->bound('sii.steps') ? app('sii.steps') : ImportsServiceProvider::SII_STEPS;

        return array_map(fn (string $class): SiiStep => app()->make($class), $classes);
    }

    /**
     * @param  list<string>  $stored  rutas subidas al disco `imports`, para borrarlas en un ensayo
     * @param  list<string>  $only
     */
    private function run(string $dir, User $user, SiiStep $step, SiiBatch $batch, Incidents $incidents, array &$stored, array $only): SiiBatchReport
    {
        $campus = $this->campus($batch);
        $path = $this->writeCsv($dir, $batch, $only);

        $upload = new UploadedFile($path, $batch->file, 'text/csv', null, true);
        $model = $this->store->handle($user, $batch->template, $upload, $campus);
        $stored[] = $model->file_path;

        $model = $this->validate->handle($model);

        if ($model->status === BatchStatus::Failed) {
            throw new SiiMigrationStopped($step->name(), $batch->file, 'el lote no pasó la validación', 0);
        }

        $model = $this->apply->handle($model, $user)->refresh();

        // Después de aplicar: una fila puede pasar a error al escribirse.
        $this->collectIssues($step, $batch, $model, $incidents);

        $warnings = ImportRow::query()->where('batch_id', $model->id)->where('status', RowStatus::Warning)->count();

        return new SiiBatchReport(
            $batch->template,
            $batch->file,
            $model->total_rows,
            $model->valid_rows,
            $warnings,
            $model->error_rows,
            $model->created_rows,
            $model->updated_rows,
        );
    }

    /**
     * El reporte de un paso que escribió por su cuenta, tal cual; sus incidencias van a nombre del paso y del archivo.
     */
    private function report(SiiStep $step, SiiBatchReport $report, Incidents $incidents): SiiBatchReport
    {
        foreach ($report->incidents as $incident) {
            $incidents->add($step->name(), $report->file, $incident['ref'], '', $incident['level'], $incident['message']);
        }

        return $report;
    }

    private function campus(SiiBatch $batch): ?Campus
    {
        if ($batch->campus === null) {
            return null;
        }

        return Campus::query()->where('code', $batch->campus)->first()
            ?? throw new InvalidArgumentException("No existe el campus {$batch->campus}.");
    }

    /**
     * @param  list<string>  $only
     */
    private function writeCsv(string $dir, SiiBatch $batch, array $only): string
    {
        $folder = rtrim($dir, '/').'/'.self::stagingFolderName($only);

        if (! is_dir($folder) && ! mkdir($folder, 0775, true) && ! is_dir($folder)) {
            throw new RuntimeException('No se pudo crear la carpeta '.basename($folder).'.');
        }

        $fields = $this->templates->get($batch->template)->fields();
        $path = "{$folder}/{$batch->file}";
        $stream = fopen($path, 'w');

        if ($stream === false) {
            throw new RuntimeException("No se pudo escribir {$batch->file}.");
        }

        fputcsv($stream, array_values($fields), escape: '');

        foreach ($batch->rows as $row) {
            fputcsv($stream, array_map(fn (string $field): mixed => $row[$field] ?? '', array_keys($fields)), escape: '');
        }

        fclose($stream);

        return $path;
    }

    private function collectIssues(SiiStep $step, SiiBatch $batch, ImportBatch $model, Incidents $incidents): void
    {
        foreach ($batch->incidents as $incident) {
            $incidents->add($step->name(), $batch->file, $incident['ref'], '', $incident['level'], $incident['message']);
        }

        ImportRow::query()
            ->where('batch_id', $model->id)
            ->whereIn('status', [RowStatus::Warning, RowStatus::Error])
            ->orderBy('row_number')
            ->each(function (ImportRow $row) use ($step, $batch, $incidents): void {
                foreach (['error' => $row->errors, 'warning' => $row->warnings] as $level => $byField) {
                    foreach ($byField ?? [] as $field => $messages) {
                        foreach ((array) $messages as $message) {
                            $incidents->add($step->name(), $batch->file, $row->row_number, (string) $field, $level, (string) $message);
                        }
                    }
                }
            });
    }
}
