<?php

namespace App\Modules\Communication\Jobs;

use App\Modules\Communication\Enums\PhotoBatchKind;
use App\Modules\Communication\Enums\PhotoBatchStatus;
use App\Modules\Communication\Enums\PhotoOutcome;
use App\Modules\Communication\Models\PhotoBatch;
use App\Modules\Core\Actions\SetStaffPhoto;
use App\Modules\Core\Exceptions\PhotoNotSaved;
use App\Modules\Core\Models\User;
use App\Modules\Core\Rules\UserAccountRules;
use App\Modules\Core\Support\PhotoNormalizer;
use App\Modules\Core\Support\StaffPhotos;
use App\Modules\Students\Actions\SetStudentPhoto;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Support\StudentPhotos;
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
 * del ZIP se ignoran, también las de `__MACOSX` y los archivos ocultos) y lo aplica SetStaffPhoto. De alumnos (CO-06): se
 * llama `<número de control>.<ext>` y lo aplica SetStudentPhoto; la cuenta del personal debe estar activa (si no, «sin persona»); un número que no existe, o sin año de ingreso legible,
 * es «sin persona». Un solo intento:
 * si el ZIP no se puede abrir o el job muere, failed() deja el lote «fallido». Al terminar, bien o mal, se borra el ZIP (CO-S10). `timeout` queda por debajo del
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

    public function handle(SetStaffPhoto $setStaffPhoto, SetStudentPhoto $setStudentPhoto, UserAccountRules $accounts): void
    {
        $batch = PhotoBatch::query()->find($this->batchId);

        if ($batch === null || $batch->status !== PhotoBatchStatus::EnCola) {
            return;
        }

        $batch->update(['status' => PhotoBatchStatus::Procesando, 'started_at' => now()]);

        $report = match ($batch->kind) {
            PhotoBatchKind::Personal => $this->applyStaff($batch, $setStaffPhoto, $accounts),
            PhotoBatchKind::Alumnos => $this->applyStudents($batch, $setStudentPhoto),
        };

        $batch->update([
            'status' => PhotoBatchStatus::Terminado,
            'report' => $report,
            'processed' => count($report),
            'applied' => count(array_filter($report, fn (array $row): bool => $row['outcome'] === PhotoOutcome::Aplicada->value)),
            'finished_at' => now(),
        ]);

        $this->discardZip($batch);
    }

    public function failed(Throwable $exception): void
    {
        $batch = PhotoBatch::query()->find($this->batchId);

        if ($batch !== null) {
            $this->discardZip($batch);
        }

        PhotoBatch::query()->whereKey($this->batchId)->update([
            'status' => PhotoBatchStatus::Fallido,
            'error' => mb_substr($exception->getMessage(), 0, 500),
            'finished_at' => now(),
        ]);
    }

    /**
     * CO-S10: el ZIP trae fotos de personas y ya no hace falta; se borra al terminar el lote, bien o mal. El reporte se queda.
     */
    private function discardZip(PhotoBatch $batch): void
    {
        Storage::disk('imports')->delete($batch->file_path);
    }

    /**
     * @return list<array{file: string, key: string, outcome: string, message: ?string}>
     */
    private function applyStaff(PhotoBatch $batch, SetStaffPhoto $setStaffPhoto, UserAccountRules $accounts): array
    {
        return $this->applyEach($batch, fn (ZipArchive $zip, int $index, string $file, string $key, callable $row, User $uploader, callable $claim): array => $this->applyOne($zip, $index, $file, $key, $claim, $setStaffPhoto, $accounts, $uploader, $row));
    }

    /**
     * @return list<array{file: string, key: string, outcome: string, message: ?string}>
     */
    private function applyStudents(PhotoBatch $batch, SetStudentPhoto $setStudentPhoto): array
    {
        return $this->applyEach($batch, fn (ZipArchive $zip, int $index, string $file, string $key, callable $row, User $uploader, callable $claim): array => $this->applyOneStudent($zip, $index, $file, $key, $claim, $setStudentPhoto, $uploader, $row));
    }

    /**
     * Recorre los archivos del ZIP (la clave repetida no se aplica dos veces) y arma el reporte con lo que devuelve `$apply`.
     *
     * @param  callable(ZipArchive, int, string, string, callable, User, callable): array{file: string, key: string, outcome: string, message: ?string}  $apply
     * @return list<array{file: string, key: string, outcome: string, message: ?string}>
     */
    private function applyEach(PhotoBatch $batch, callable $apply): array
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

            // Una clave solo cuenta como vista cuando su archivo pasó el formato: «ana.txt» no tapa a «ana.jpg».
            $claim = function () use (&$seen, $folded): bool {
                if (isset($seen[$folded])) {
                    return false;
                }

                return $seen[$folded] = true;
            };
            $report[] = $apply($zip, $index, $file, $key, $row, $uploader, $claim);

            if (($position + 1) % self::SAVE_EVERY === 0) {
                $batch->update(['processed' => $position + 1, 'report' => $report]);
            }
        }

        $zip->close();

        return $report;
    }

    /**
     * @param  callable(): bool  $claim  marca la clave como vista; false si otro archivo del lote ya la traía
     * @param  callable(PhotoOutcome, ?string=): array{file: string, key: string, outcome: string, message: ?string}  $row
     * @return array{file: string, key: string, outcome: string, message: ?string}
     */
    private function applyOne(ZipArchive $zip, int $index, string $file, string $key, callable $claim, SetStaffPhoto $setStaffPhoto, UserAccountRules $accounts, User $uploader, callable $row): array
    {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $size = (int) ($zip->statIndex($index)['size'] ?? 0);

        if ($size > PhotoNormalizer::MAX_BYTES) {
            return $row(PhotoOutcome::MuyPesada, 'Pesa más de 10 MB.');
        }

        if (! in_array($extension, StaffPhotos::EXTENSIONS, true)) {
            return $row(PhotoOutcome::FormatoInvalido, 'Solo jpg, png o webp.');
        }

        if (! $claim()) {
            return $row(PhotoOutcome::Repetida, 'Otro archivo del lote ya traía este usuario.');
        }

        $person = User::query()->whereRaw('lower(username) = ?', [mb_strtolower($key)])->first();

        if ($person === null) {
            return $row(PhotoOutcome::SinPersona, 'No hay personal con ese usuario.');
        }

        if ($accounts->isStudent($person)) {
            return $row(PhotoOutcome::EsAlumno, 'Es alumno: sus fotos no van en este lote.');
        }

        if (! $person->is_active) {
            return $row(PhotoOutcome::SinPersona, 'La cuenta no está activa.');
        }

        $temporary = sys_get_temp_dir().'/nxph-'.Str::uuid().".{$extension}";

        try {
            file_put_contents($temporary, (string) $zip->getFromIndex($index));
            chmod($temporary, 0600);
            $setStaffPhoto->handle($person, $temporary, $uploader);
        } catch (ValidationException $e) {
            $message = (string) collect($e->errors())->flatten()->first();

            return $row($message === PhotoNotSaved::MESSAGE ? PhotoOutcome::NoGuardada : PhotoOutcome::FormatoInvalido, $message);
        } finally {
            @unlink($temporary);
        }

        return $row(PhotoOutcome::Aplicada);
    }

    /**
     * @param  callable(): bool  $claim  marca la clave como vista; false si otro archivo del lote ya la traía
     * @param  callable(PhotoOutcome, ?string=): array{file: string, key: string, outcome: string, message: ?string}  $row
     * @return array{file: string, key: string, outcome: string, message: ?string}
     */
    private function applyOneStudent(ZipArchive $zip, int $index, string $file, string $key, callable $claim, SetStudentPhoto $setStudentPhoto, User $uploader, callable $row): array
    {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $size = (int) ($zip->statIndex($index)['size'] ?? 0);

        if ($size > PhotoNormalizer::MAX_BYTES) {
            return $row(PhotoOutcome::MuyPesada, 'Pesa más de 10 MB.');
        }

        if (! in_array($extension, StudentPhotos::EXTENSIONS, true)) {
            return $row(PhotoOutcome::FormatoInvalido, 'Solo jpg, png o webp.');
        }

        if (! $claim()) {
            return $row(PhotoOutcome::Repetida, 'Otro archivo del lote ya traía este número de control.');
        }

        $student = StudentPhotos::yearFolder($key) === null ? null : Student::query()->whereRaw('lower(control_number) = ?', [mb_strtolower($key)])->first();

        if ($student === null) {
            return $row(PhotoOutcome::SinPersona, 'No hay un alumno con ese número de control.');
        }

        $temporary = sys_get_temp_dir().'/nxph-'.Str::uuid().".{$extension}";

        try {
            file_put_contents($temporary, (string) $zip->getFromIndex($index));
            chmod($temporary, 0600);
            $setStudentPhoto->handle($student, $temporary, $uploader);
        } catch (ValidationException $e) {
            $message = (string) collect($e->errors())->flatten()->first();

            return $row($message === PhotoNotSaved::MESSAGE ? PhotoOutcome::NoGuardada : PhotoOutcome::FormatoInvalido, $message);
        } finally {
            @unlink($temporary);
        }

        return $row(PhotoOutcome::Aplicada);
    }

    /**
     * Un nombre del ZIP en UTF-8: el Explorador de Windows guarda los nombres con acentos en CP437 (sin la marca UTF-8), y se leen en bruto
     * para decidir aquí: si no son UTF-8 válido se leen como CP850, que coincide en las letras del español, y lo que aún quede inválido se limpia, para que el reporte
     * (JSON) siempre se pueda guardar.
     */
    private static function utf8(string $name): string
    {
        if (! mb_check_encoding($name, 'UTF-8')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'CP850');
        }

        return mb_scrub($name);
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
            $name = self::utf8((string) $zip->getNameIndex($index, ZipArchive::FL_ENC_RAW));

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
