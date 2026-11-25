<?php

namespace App\Modules\Students\Support;

use App\Modules\Students\Models\Student;
use Illuminate\Support\Facades\URL;

/**
 * La URL de la foto de un alumno para quien lo consulta (CO-06, CO-S7): una ruta `students.photo` firmada que vence a las
 * 2 horas. Solo la arma una pantalla que ya decidió que quien mira puede ver a ese alumno (la ficha, «Fotos de
 * alumnos»); el controlador la vuelve a revisar. La versión va en la ruta: una foto nueva da otra URL. La foto propia
 * del alumno sigue en `students.my-record.photo`.
 */
final class StudentPhotoUrl
{
    public const int TTL_SECONDS = 7200;

    public static function for(Student $student): ?string
    {
        $version = app(StudentPhotos::class)->version($student->control_number);

        if ($version === null) {
            return null;
        }

        return URL::temporarySignedRoute('students.photo', now()->addSeconds(self::TTL_SECONDS), ['student' => $student->getKey(), 'version' => $version]);
    }
}
