<?php

namespace App\Modules\Imports\Actions;

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Enums\BatchStatus;
use App\Modules\Imports\Events\BatchUploaded;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportMapping;
use App\Modules\Imports\Readers\SpreadsheetReader;
use App\Modules\Imports\Templates\TemplateRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;
use Throwable;

/**
 * Registra un archivo subido para importar: lo guarda en el disco `imports` como `<id del lote>.<ext>`
 * (storage/app/imports, fuera de git: CLAUDE.md, regla dura 9) y crea el lote `uploaded`. No lo lee:
 * validar es ValidateImportBatch, en cola. No sabe de roles: quién sube lo decide la página con
 * ImportBatchPolicy.
 *
 * Rechaza con una ValidationException por campo, sin guardar nada: una plantilla que no existe, un
 * archivo que SpreadsheetReader no lee, una plantilla de campus sin campus (el de la topbar), y un
 * mapeo de otra plantilla, desactivado o que apunta a campos que la plantilla ya no tiene. En una
 * plantilla que no es de un campus, el campus no se guarda. Un nombre de archivo de más de 255
 * caracteres se recorta y conserva su extensión.
 *
 * El archivo se guarda antes que el lote, para que exista cuando alguien lea el lote. Si esta
 * transacción o una de afuera que la contenga se revierte, el archivo se borra: trae datos personales
 * y no debe quedar sin lote. Después del commit avisa con BatchUploaded.
 */
final class StoreImportBatch
{
    public function __construct(private readonly TemplateRegistry $templates) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $user, string $template, UploadedFile $file, ?Campus $campus = null, ?ImportMapping $mapping = null): ImportBatch
    {
        if (! $this->templates->has($template)) {
            throw ValidationException::withMessages(['template' => 'Elige una plantilla de importación.']);
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, SpreadsheetReader::EXTENSIONS, true)) {
            throw ValidationException::withMessages(['file' => 'El archivo debe ser .xlsx, .csv o .txt.']);
        }

        $definition = $this->templates->get($template);

        if ($definition->requiresCampus() && $campus === null) {
            throw ValidationException::withMessages(['campus' => 'Elige en la barra superior el campus al que pertenece el archivo.']);
        }

        if ($mapping !== null) {
            $this->ensureMappingFits($mapping, $template, array_keys($definition->fields()));
        }

        $batch = new ImportBatch([
            'template' => $template,
            'campus_id' => $definition->requiresCampus() ? $campus->id : null,
            'mapping_id' => $mapping?->id,
            'original_name' => $this->storedName($file->getClientOriginalName(), $extension),
            'status' => BatchStatus::Uploaded,
            'uploaded_by' => $user->id,
        ]);
        $batch->id = $batch->newUniqueId();
        $batch->file_path = "{$batch->id}.{$extension}";

        $disk = Storage::disk('imports');
        $disk->putFileAs('', $file, $batch->file_path);

        try {
            return DB::transaction(function () use ($batch, $user, $definition, $disk): ImportBatch {
                // Si una transacción de afuera se revierte después de este commit, el lote desaparece.
                DB::afterRollBack(fn () => $disk->delete($batch->file_path));

                $batch->save();

                activity()
                    ->performedOn($batch)
                    ->causedBy($user)
                    ->event(ActivityEvent::Created)
                    ->withProperties(['template' => $batch->template, 'campus_id' => $batch->campus_id])
                    ->log("Subió {$batch->original_name} para importar {$definition->label()}");

                BatchUploaded::dispatch($batch);

                return $batch;
            });
        } catch (Throwable $exception) {
            $disk->delete($batch->file_path);

            throw $exception;
        }
    }

    /**
     * @param  list<string>  $fields
     *
     * @throws ValidationException
     */
    private function ensureMappingFits(ImportMapping $mapping, string $template, array $fields): void
    {
        if ($mapping->template !== $template) {
            throw ValidationException::withMessages(['mapping' => 'El mapeo de columnas es de otra plantilla.']);
        }

        if (! $mapping->is_active) {
            throw ValidationException::withMessages(['mapping' => 'El mapeo de columnas está desactivado.']);
        }

        $unknown = array_values(array_diff($mapping->columns, $fields));

        if ($unknown !== []) {
            throw ValidationException::withMessages(['mapping' => 'El mapeo de columnas apunta a campos que la plantilla ya no tiene: '.implode(', ', $unknown).'.']);
        }
    }

    /**
     * El nombre original en a lo más 255 caracteres (el largo de la columna), con su extensión.
     */
    private function storedName(string $name, string $extension): string
    {
        if (mb_strlen($name) <= 255) {
            return $name;
        }

        return mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 254 - mb_strlen($extension)).'.'.$extension;
    }
}
