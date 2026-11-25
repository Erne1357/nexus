<?php

namespace App\Modules\Students\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se guardó una foto nueva del alumno (SetStudentPhoto), ya con la transacción confirmada. Lo escuchan, a su derecha en
 * el mapa, las solicitudes de credencial (CO-S8: entregar una solicitud con foto exige una foto subida mientras estaba abierta).
 */
final class StudentPhotoChanged
{
    use Dispatchable;

    public function __construct(public readonly string $studentId) {}
}
