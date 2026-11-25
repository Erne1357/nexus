<?php

namespace App\Modules\Students\Support;

use App\Modules\Core\Support\StaffPhotoUrl;
use App\Modules\Students\Models\Student;
use Illuminate\Support\Facades\URL;

/**
 * La URL de la foto de un alumno para quien lo consulta (CO-06, CO-S7): una ruta `students.photo` firmada que vence a las
 * 2 a 3 horas (el mismo vencimiento redondeado de StaffPhotoUrl: la URL no cambia dentro de la hora, y la caché privada sirve). Solo la arma una pantalla que ya decidió que quien mira puede ver a ese alumno (la ficha, «Fotos de
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

        return URL::temporarySignedRoute('students.photo', StaffPhotoUrl::stableExpiry(), ['student' => $student->getKey(), 'version' => $version]);
    }

    /**
     * La URL de la foto propia del alumno (`students.my-record.photo`, sin id: solo le responde a él). `v` cambia por
     * alumno y por foto, y la ruta responde sin caché, así que no se filtra entre alumnos de una computadora compartida.
     */
    public static function own(Student $student): ?string
    {
        $version = app(StudentPhotos::class)->version($student->control_number);

        return $version === null ? null : route('students.my-record.photo', ['v' => substr(hash('sha256', $student->getKey().'|'.$version), 0, 16)]);
    }
}
