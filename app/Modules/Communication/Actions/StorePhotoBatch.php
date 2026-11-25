<?php

namespace App\Modules\Communication\Actions;

use App\Modules\Communication\Enums\PhotoBatchKind;
use App\Modules\Communication\Enums\PhotoBatchStatus;
use App\Modules\Communication\Jobs\ProcessPhotoBatch;
use App\Modules\Communication\Models\PhotoBatch;
use App\Modules\Core\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;
use Throwable;
use ZipArchive;

/**
 * Recibe un lote de fotos en ZIP (CO-S5): lo guarda en el disco `imports` (con datos personales: fuera de git y de
 * public), crea el lote «en cola» y manda ProcessPhotoBatch a la cola después del commit. Un ZIP pesa hasta 19 MB. Los
 * hay del personal y de alumnos (CO-06).
 */
final class StorePhotoBatch
{
    public const int MAX_BYTES = 19 * 1024 * 1024;

    /**
     * @throws ValidationException si el archivo no es un ZIP legible o pesa de más
     */
    public function handle(PhotoBatchKind $kind, UploadedFile $zip, User $by): PhotoBatch
    {
        $this->assertZip($zip);

        $path = $zip->storeAs('photo-batches', Str::uuid()->toString().'.zip', 'imports');

        try {
            return DB::transaction(function () use ($kind, $zip, $by, $path): PhotoBatch {
                $batch = PhotoBatch::query()->create([
                    'kind' => $kind,
                    'status' => PhotoBatchStatus::EnCola,
                    'file_path' => $path,
                    'original_name' => Str::limit($zip->getClientOriginalName(), 200, ''),
                    'uploaded_by' => $by->getKey(),
                ]);

                activity()->performedOn($batch)->causedBy($by)->event(ActivityEvent::Created)->log($kind === PhotoBatchKind::Alumnos ? 'Subió un lote de fotos de alumnos' : 'Subió un lote de fotos del personal');

                ProcessPhotoBatch::dispatch($batch->id)->afterCommit();

                return $batch;
            });
        } catch (Throwable $e) {
            Storage::disk('imports')->delete($path);

            throw $e;
        }
    }

    private function assertZip(UploadedFile $zip): void
    {
        if ($zip->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages(['zip' => 'El ZIP pesa más de 19 MB. Súbelo en varios lotes.']);
        }

        $archive = new ZipArchive;
        $opened = $archive->open((string) $zip->getRealPath(), ZipArchive::RDONLY);

        if ($opened !== true) {
            throw ValidationException::withMessages(['zip' => 'No se pudo leer el ZIP.']);
        }

        $archive->close();
    }
}
