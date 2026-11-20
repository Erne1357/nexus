<?php

namespace App\Modules\Students\Http\Controllers;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Students\Enums\StudentsPermission;
use App\Modules\Students\Queries\MyRecordQuery;
use App\Modules\Students\Support\StudentPhotos;
use Illuminate\Http\Response;

/**
 * La foto de la credencial del propio alumno (AC-20, tarea 1.27). Solo la pide él (`students.my_record.view` sobre lo
 * suyo); la ruta no lleva id, así que no se puede pedir la de otro. Sin foto, 404.
 */
final class MyCredentialPhoto
{
    public function __invoke(Actor $context, MyRecordQuery $records, StudentPhotos $photos): Response
    {
        $user = $context->user();
        abort_unless($user !== null && $context->allows(StudentsPermission::MyRecordView, Target::ownedBy((string) $user->getKey())), 403);

        $student = $records->for($user);
        $photo = $student === null ? null : $photos->read($student->control_number);
        abort_if($photo === null, 404);

        return response($photo['bytes'], 200, [
            'Content-Type' => $photo['mime'],
            'Cache-Control' => 'private, max-age=600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
