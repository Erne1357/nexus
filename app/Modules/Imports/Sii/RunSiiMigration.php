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
 * @throws SiiContractBroken antes de escribir nada, si la exportación no cumple el contrato
 */
final class RunSiiMigration
{
    public function __construct(
        private readonly StoreImportBatch $store,
        private readonly ValidateImportBatch $validate,
        private readonly ApplyImportBatch $apply,
        private readonly TemplateRegistry $templates,
    ) {}

    /**
     * @param  list<string>  $only  nombres de pasos; vacío corre todos
     *
     * @throws SiiContractBroken
     * @throws InvalidArgumentException campus desconocido o plantilla inexistente
     */
    public function handle(string $dir, User $user, bool $dryRun, array $only = []): SiiMigrationReport
    {
        $export = SiiExport::open($dir);
        $migration = new SiiMigration($export, __DIR__.'/Curated');
        $incidents = new Incidents;
        $reports = [];
        $stored = [];
        $queue = config('queue.default');

        if ($dryRun) {
            config(['queue.default' => 'null']);
            DB::beginTransaction();
        }

        try {
            foreach ($this->steps() as $step) {
                if ($only !== [] && ! in_array($step->name(), $only, true)) {
                    continue;
                }

                foreach ($step->batches($migration) as $batch) {
                    $reports[] = $this->run($dir, $user, $step, $batch, $incidents, $stored);
                }
            }
        } finally {
            if ($dryRun) {
                DB::rollBack();
                config(['queue.default' => $queue]);

                foreach ($stored as $path) {
                    Storage::disk('imports')->delete($path);
                }
            }

            if ($incidents->count() > 0 || $reports !== []) {
                $incidents->write(rtrim($dir, '/').'/_incidencias.csv');
            }
        }

        return new SiiMigrationReport($reports, $incidents->count());
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
            throw new RuntimeException("El lote {$batch->file} no se pudo validar: {$model->failure_reason}");
        }

        $this->collectIssues($step, $batch, $model, $incidents);

        $warnings = ImportRow::query()->where('batch_id', $model->id)->where('status', RowStatus::Warning)->count();

        $model = $this->apply->handle($model, $user)->refresh();

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
