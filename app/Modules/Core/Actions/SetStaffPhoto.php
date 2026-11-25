<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Exceptions\InvalidPhoto;
use App\Modules\Core\Exceptions\PhotoNotSaved;
use App\Modules\Core\Models\User;
use App\Modules\Core\Rules\UserAccountRules;
use App\Modules\Core\Support\StaffPhotos;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Pone la foto de una persona del personal (CO-03, C11): la normaliza (3:4, 600×800 y miniatura), la guarda sin borrar
 * las anteriores y la deja como la vigente. Nunca a un usuario de alumno (CO-03). Deja bitácora sobre la persona,
 * «Cambió la foto», sin la imagen (`$by` nulo desde el comando core:provision-staff, CO-11). Quién puede lo decide UserPolicy::managePhotos.
 */
final class SetStaffPhoto
{
    public function __construct(private readonly StaffPhotos $photos, private readonly UserAccountRules $accounts) {}

    /**
     * @param  UploadedFile|string  $file  el archivo subido o la ruta de uno en disco (el lote en ZIP)
     *
     * @throws ValidationException si la persona es un alumno, la imagen no sirve o el disco no deja guardarla
     */
    public function handle(User $person, UploadedFile|string $file, ?User $by = null): void
    {
        if ($this->accounts->isStudent($person)) {
            throw ValidationException::withMessages(['photo' => 'Es alumno: sus fotos van en la sección de alumnos, no en las del personal.']);
        }

        $path = $file instanceof UploadedFile ? (string) $file->getRealPath() : $file;
        $extension = $file instanceof UploadedFile ? $file->getClientOriginalExtension() : pathinfo($file, PATHINFO_EXTENSION);

        try {
            $stored = $this->photos->store($person, $path, $extension);
        } catch (InvalidPhoto $e) {
            throw ValidationException::withMessages(['photo' => $e->getMessage()]);
        } catch (PhotoNotSaved $e) {
            report($e);

            throw ValidationException::withMessages(['photo' => PhotoNotSaved::MESSAGE]);
        }

        DB::transaction(function () use ($person, $stored, $by): void {
            $person->forceFill(['photo_path' => $stored, 'photo_updated_at' => now()])->save();

            activity()->performedOn($person)->causedBy($by)->event(ActivityEvent::Updated)->log('Cambió la foto');
        });
    }
}
