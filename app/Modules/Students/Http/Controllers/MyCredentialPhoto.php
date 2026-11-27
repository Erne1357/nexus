<?php

namespace App\Modules\Students\Http\Controllers;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Students\Enums\StudentsPermission;
use App\Modules\Students\Queries\MyRecordQuery;
use App\Modules\Students\Support\StudentPhotos;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * La foto de la credencial del propio alumno (AC-20, tarea 1.27). Solo la pide él (`students.my_record.view` sobre lo
 * suyo); la ruta no lleva id, así que no se puede pedir la de otro. Sin foto, 404. Sale en flujo y sin caché.
 */
final class MyCredentialPhoto
{
    public function __invoke(Actor $context, MyRecordQuery $records, StudentPhotos $photos): StreamedResponse
    {
        $user = $context->user();
        abort_unless($user !== null && $context->allows(StudentsPermission::MyRecordView, Target::ownedBy((string) $user->getKey())), 403);

        $student = $records->for($user);
        $response = $student === null ? null : $photos->stream($student->control_number, [
            // La URL es la misma para todos los alumnos: sin guardarla, en una computadora compartida el siguiente no ve
            // la foto del anterior. La página le agrega `?v=` por alumno y por foto.
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        abort_if($response === null, 404);

        return $response;
    }
}
