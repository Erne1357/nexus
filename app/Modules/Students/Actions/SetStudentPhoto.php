<?php

namespace App\Modules\Students\Actions;

use App\Modules\Core\Exceptions\InvalidPhoto;
use App\Modules\Core\Exceptions\PhotoNotSaved;
use App\Modules\Core\Models\User;
use App\Modules\Students\Events\StudentPhotoChanged;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Support\StudentPhotos;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Pone la foto de la credencial de un alumno (CO-06, C1): la normaliza (3:4, 600×800), la guarda como
 * `<AAAA>/<número de control>.jpg` y mueve la anterior a `_anteriores/` sin borrarla. Deja bitácora sobre el alumno,
 * «Cambió la foto de la credencial», sin la imagen, y avisa con StudentPhotoChanged al terminar. Quién puede lo decide
 * StudentPolicy::managePhotos.
 */
final class SetStudentPhoto
{
    public function __construct(private readonly StudentPhotos $photos) {}

    /**
     * @param  UploadedFile|string  $file  el archivo subido o la ruta de uno en disco (el lote en ZIP)
     *
     * @throws ValidationException si el número de control no trae el año de ingreso o la imagen no sirve
     */
    public function handle(Student $student, UploadedFile|string $file, User $by): void
    {
        if (StudentPhotos::yearFolder($student->control_number) === null) {
            throw ValidationException::withMessages(['photo' => 'Su número de control no trae el año de ingreso: no se le puede guardar la foto.']);
        }

        $path = $file instanceof UploadedFile ? (string) $file->getRealPath() : $file;

        try {
            DB::transaction(function () use ($student, $path, $by): void {
                $this->photos->store($student->control_number, $path);

                activity()->performedOn($student)->causedBy($by)->event(ActivityEvent::Updated)->log('Cambió la foto de la credencial');
            });
        } catch (InvalidPhoto $e) {
            throw ValidationException::withMessages(['photo' => $e->getMessage()]);
        } catch (PhotoNotSaved $e) {
            report($e);

            throw ValidationException::withMessages(['photo' => PhotoNotSaved::MESSAGE]);
        }

        StudentPhotoChanged::dispatch((string) $student->getKey());
    }
}
