<?php

namespace App\Modules\Students\Actions;

use App\Modules\Students\Models\CredentialRequest;
use App\Modules\Students\Models\Student;

/**
 * CO-S8: se subió una foto del alumno. Marca `photo_updated_at` en sus solicitudes abiertas que incluyen foto, y
 * DeliverCredentialRequest lo exige para entregarlas. La llama SetStudentPhoto, desde cualquier pantalla o lote.
 */
final class TouchOpenPhotoRequests
{
    public function handle(Student $student): void
    {
        CredentialRequest::query()
            ->where('student_id', $student->getKey())
            ->where('wants_photo', true)
            ->open()
            ->update(['photo_updated_at' => now()]);
    }
}
