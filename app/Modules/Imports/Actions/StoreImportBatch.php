<?php

namespace App\Modules\Imports\Actions;

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Enums\BatchStatus;
use App\Modules\Imports\Events\BatchUploaded;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportMapping;
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
 * Rechaza con una ValidationException por campo, sin guardar nada, una plantilla que no existe, un
 * archivo que no es .xlsx ni .csv, una plantilla de campus sin campus (el de la topbar) y un mapeo de
 * otra plantilla. En una plantilla que no es de un campus, el campus no se guarda.
 *
 * El archivo se guarda antes de la transacción; si la transacción falla, se borra. Después del
 * commit avisa con BatchUploaded.
 */
final class StoreImportBatch
{
    /**
     * Extensiones que SpreadsheetReader sabe leer.
     */
    private const array EXTENSIONS = ['csv', 'txt', 'xlsx'];

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

        if (! in_array($extension, self::EXTENSIONS, true)) {
            throw ValidationException::withMessages(['file' => 'El archivo debe ser .xlsx o .csv.']);
        }

        $definition = $this->templates->get($template);

        if ($definition->requiresCampus() && $campus === null) {
            throw ValidationException::withMessages(['campus' => 'Elige en la barra superior el campus al que pertenece el archivo.']);
        }

        if ($mapping !== null && $mapping->template !== $template) {
            throw ValidationException::withMessages(['mapping' => 'El mapeo de columnas es de otra plantilla.']);
        }

        $batch = new ImportBatch([
            'template' => $template,
            'campus_id' => $definition->requiresCampus() ? $campus->id : null,
            'mapping_id' => $mapping?->id,
            'original_name' => $file->getClientOriginalName(),
            'status' => BatchStatus::Uploaded,
            'uploaded_by' => $user->id,
        ]);
        $batch->id = $batch->newUniqueId();
        $batch->file_path = "{$batch->id}.{$extension}";

        $disk = Storage::disk('imports');
        $disk->putFileAs('', $file, $batch->file_path);

        try {
            return DB::transaction(function () use ($batch, $user, $definition): ImportBatch {
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
}
