<?php

namespace App\Modules\Communication\Jobs;

use App\Modules\Communication\Enums\PhotoBatchKind;
use App\Modules\Communication\Enums\PhotoBatchStatus;
use App\Modules\Communication\Enums\PhotoOutcome;
use App\Modules\Communication\Models\PhotoBatch;
use App\Modules\Core\Actions\SetStaffPhoto;
use App\Modules\Core\Models\User;
use App\Modules\Core\Rules\UserAccountRules;
use App\Modules\Core\Support\PhotoNormalizer;
use App\Modules\Core\Support\StaffPhotos;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;
use ZipArchive;

/**
 * Procesa un lote de fotos en ZIP en la cola (CO-S5): según el tipo del lote, aplica cada archivo a su persona y deja un
 * reporte por archivo. Del personal: el archivo se llama `<usuario>.<ext>` (sin distinguir mayúsculas; las carpetas
 * del ZIP se ignoran, también las de `__MACOSX` y los archivos ocultos) y lo aplica SetStaffPhoto. Un solo intento:
 * si el ZIP no se puede abrir o el job muere, failed() deja el lote «fallido». `timeout` queda por debajo del
 * `retry_after` de la conexión (config/queue.php).
 */
final class ProcessPhotoBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1500;

    /** Cada cuántos archivos guarda el avance para que la pantalla lo vea. */
    private const int SAVE_EVERY = 5;

    public function __construct(public string $batchId) {}

    public function handle(SetStaffPhoto $setStaffPhoto, UserAccountRules $accounts): void
    {
        $batch = PhotoBatch::query()->find($this->batchId);

        if ($batch === null || $batch->status !== PhotoBatchStatus::EnCola) {
            return;
        }

        $batch->update(['status' => PhotoBatchStatus::Procesando, 'started_at' => now()]);

        $report = match ($batch->kind) {
            PhotoBatchKind::Personal => $this->applyStaff($batch, $setStaffPhoto, $accounts),
            PhotoBatchKind::Alumnos => throw new LogicException('Los lotes de alumnos llegan en la tarea 1.37.'),
        };

        $batch->update([
            'status' => PhotoBatchStatus::Terminado,
            'report' => $report,
            'processed' => count($report),
            'applied' => count(array_filter($report, fn (array $row): bool => $row['outcome'] === PhotoOutcome::Aplicada->value)),
            'finished_at' => now(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        PhotoBatch::query()->whereKey($this->batchId)->update([
            'status' => PhotoBatchStatus::Fallido,
            'error' => mb_substr($exception->getMessage(), 0, 500),
            'finished_at' => now(),
        ]);
    }

    /**
     * @return list<array{file: string, key: string, outcome: string, message: ?string}>
     */
    private function applyStaff(PhotoBatch $batch, SetStaffPhoto $setStaffPhoto, UserAccountRules $accounts): array
    {
        $zip = new ZipArchive;

        if ($zip->open(Storage::disk('imports')->path($batch->file_path), ZipArchive::RDONLY) !== true) {
            throw new LogicException('No se pudo abrir el ZIP del lote.');
        }

        $uploader = User::query()->findOrFail($batch->uploaded_by);
        $entries = $this->entries($zip);
        $batch->update(['total' => count($entries)]);

        $report = [];
        $seen = [];

        foreach ($entries as $position => [$index, $file]) {
            $key = pathinfo($file, PATHINFO_FILENAME);
            $row = fn (PhotoOutcome $outcome, ?string $message = null): array => ['file' => $file, 'key' => $key, 'outcome' => $outcome->value, 'message' => $message];
            $folded = mb_strtolower($key);

            $report[] = $this->applyOne($zip, $index, $file, $key, $folded, $seen, $setStaffPhoto, $accounts, $uploader, $row);

            if (($position + 1) % self::SAVE_EVERY === 0) {
                $batch->update(['processed' => $position + 1, 'report' => $report]);
            }
        }

        $zip->close();

        return $report;
    }

    /**
     * @param  array<string, true>  $seen  las claves de archivos que ya pasaron el formato; una repetida se reporta, no se aplica
     * @param  callable(PhotoOutcome, ?string=): array{file: string, key: string, outcome: string, message: ?string}  $row
     * @return array{file: string, key: string, outcome: string, message: ?string}
     */
    private function applyOne(ZipArchive $zip, int $index, string $file, string $key, string $folded, array &$seen, SetStaffPhoto $setStaffPhoto, UserAccountRules $accounts, User $uploader, callable $row): array
    {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $size = (int) ($zip->statIndex($index)['size'] ?? 0);

        if ($size > PhotoNormalizer::MAX_BYTES) {
            return $row(PhotoOutcome::MuyPesada, 'Pesa más de 10 MB.');
        }

        if (! in_array($extension, StaffPhotos::EXTENSIONS, true)) {
            return $row(PhotoOutcome::FormatoInvalido, 'Solo jpg, png o webp.');
        }

        if (isset($seen[$folded])) {
            return $row(PhotoOutcome::Repetida, 'Otro archivo del lote ya traía este usuario.');
        }

        $seen[$folded] = true;

        $person = User::query()->whereRaw('lower(username) = ?', [mb_strtolower($key)])->first();

        if ($person === null) {
            return $row(PhotoOutcome::SinPersona, 'No hay personal con ese usuario.');
        }

        if ($accounts->isStudent($person)) {
            return $row(PhotoOutcome::EsAlumno, 'Es alumno: sus fotos no van en este lote.');
        }

        $temporary = sys_get_temp_dir().'/nxph-'.Str::uuid().".{$extension}";

        try {
            file_put_contents($temporary, (string) $zip->getFromIndex($index));
            chmod($temporary, 0600);
            $setStaffPhoto->handle($person, $temporary, $uploader);
        } catch (ValidationException $e) {
            return $row(PhotoOutcome::FormatoInvalido, collect($e->errors())->flatten()->first());
        } finally {
            @unlink($temporary);
        }

        return $row(PhotoOutcome::Aplicada);
    }

    /**
     * Los archivos del ZIP que cuentan, sin carpetas, sin `__MACOSX` y sin ocultos, con su nombre sin la carpeta.
     *
     * @return list<array{0: int, 1: string}> índice dentro del ZIP y nombre del archivo
     */
    private function entries(ZipArchive $zip): array
    {
        $entries = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (str_ends_with($name, '/') || str_starts_with($name, '__MACOSX/') || str_contains($name, '/__MACOSX/')) {
                continue;
            }

            $file = basename($name);

            if ($file === '' || str_starts_with($file, '.')) {
                continue;
            }

            $entries[] = [$index, $file];
        }

        return $entries;
    }
}
