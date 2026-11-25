<?php

namespace App\Modules\Students\Http\Controllers;

use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Support\StudentPhotos;
use App\Modules\Students\Support\StudentPhotoUrl;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sirve la foto vigente de un alumno a quien lo consulta (CO-06): con la URL firmada y vigente de StudentPhotoUrl, con
 * sesión y solo a quien ve la ficha del alumno (StudentPolicy::view) o administra sus fotos (managePhotos). Una versión
 * que ya no es la vigente, o un alumno sin foto, es 404.
 */
final class StudentPhoto
{
    public function __invoke(string $student, string $version, StudentPhotos $photos): StreamedResponse
    {
        $model = KeyLookup::find(Student::query(), $student);
        abort_if($model === null, 404);
        abort_unless(Gate::allows('view', $model) || Gate::allows('managePhotos', Student::class), 403);
        abort_if($photos->version($model->control_number) !== $version, 404);

        $response = $photos->stream($model->control_number, [
            'Cache-Control' => 'private, max-age='.StudentPhotoUrl::TTL_SECONDS,
            'X-Content-Type-Options' => 'nosniff',
        ]);
        abort_if($response === null, 404);

        return $response;
    }
}
