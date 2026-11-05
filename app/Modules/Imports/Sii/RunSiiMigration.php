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
 * queda en `<dir>/_incidencias.csv`, sin filas crudas.
 *
 * Con `$dryRun` todo corre dentro de una transacción que se revierte, sin cola, y se borran los archivos
 * subidos; las incidencias se leen antes de revertir. Sin `$dryRun` los archivos subidos se conservan como
 * evidencia de cada lote.
 *
 * Un campus o plantilla desconocidos se detectan al llegar a su lote, antes de guardarlo: los lotes anteriores
 * ya se aplicaron en una corrida real (son altas por clave natural, repetirlas no cambia nada) y se revierten en
 * un ensayo. Cualquier fallo se reporta como SiiMigrationStopped, sin valores. `_incidencias.csv` se reescribe
 * siempre al terminar.
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

        $migration = new SiiMigration($export, $this->curatedDir);
        $incidents = new Incidents;
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
                    $reports[] = $this->run($dir, $user, $step, $batch, $incidents, $stored);
                }
            }
        } catch (SiiMigrationStopped $stopped) {
            $pending = new SiiMigrationStopped($stopped->step, $stopped->batchFile, $stopped->cause, count($reports), $stopped->getPrevious());
        } catch (Throwable $exception) {
            report($exception);
            $pending = new SiiMigrationStopped($current[0] ?? '-', $current[1], $this->causeOf($exception), count($reports), $exception);
        }

        $cleanup = $this->cleanUp($dir, $dryRun, $queue, $level, $stored, $incidents);

        if ($pending !== null) {
            throw $pending;
        }

        if ($cleanup !== null) {
            throw new SiiMigrationStopped('-', null, 'falló la limpieza ('.class_basename($cleanup).')', count($reports), $cleanup);
        }

        return new SiiMigrationReport($reports, $incidents->count());
    }

    /**
     * Revierte el ensayo, borra lo subido y reescribe las incidencias. Cada paso corre aunque otro falle; un
     * fallo se reporta al log y se devuelve el primero, sin tapar un error pendiente.
     *
     * @param  list<string>  $stored
     */
    private function cleanUp(string $dir, bool $dryRun, mixed $queue, int $level, array $stored, Incidents $incidents): ?Throwable
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
            fn () => $incidents->write(rtrim($dir, '/').'/_incidencias.csv'),
        ];

        foreach ($steps as $step) {
            try {
                $step();
            } catch (Throwable $failure) {
                report($failure);
                $first ??= $failure;
            }
        }

        return $first;
    }

    /**
     * Una causa fija: nunca el mensaje de la excepción, que puede traer valores. Solo SiiCuratedMismatch, que solo nombra claves.
     */
    private function causeOf(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof SiiCuratedMismatch => $exception->getMessage(),
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
     */
    private function run(string $dir, User $user, SiiStep $step, SiiBatch $batch, Incidents $incidents, array &$stored): SiiBatchReport
    {
        $campus = $this->campus($batch);
        $path = $this->writeCsv($dir, $batch);

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

    private function campus(SiiBatch $batch): ?Campus
    {
        if ($batch->campus === null) {
            return null;
        }

        return Campus::query()->where('code', $batch->campus)->first()
            ?? throw new InvalidArgumentException("No existe el campus {$batch->campus}.");
    }

    private function writeCsv(string $dir, SiiBatch $batch): string
    {
        $folder = rtrim($dir, '/').'/_nexus';

        if (! is_dir($folder) && ! mkdir($folder, 0775, true) && ! is_dir($folder)) {
            throw new RuntimeException('No se pudo crear la carpeta _nexus.');
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
