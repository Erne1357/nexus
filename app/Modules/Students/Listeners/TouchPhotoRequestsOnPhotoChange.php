<?php

namespace App\Modules\Students\Listeners;

use App\Modules\Students\Actions\TouchOpenPhotoRequests;
use App\Modules\Students\Events\StudentPhotoChanged;
use App\Modules\Students\Models\Student;

/**
 * CO-S8: al guardarse una foto nueva del alumno (SetStudentPhoto, desde cualquier pantalla o lote), marca sus solicitudes
 * abiertas que incluyen foto, para que puedan entregarse.
 */
final class TouchPhotoRequestsOnPhotoChange
{
    public function __construct(private readonly TouchOpenPhotoRequests $touch) {}

    public function handle(StudentPhotoChanged $event): void
    {
        $student = Student::query()->find($event->studentId);

        if ($student !== null) {
            $this->touch->handle($student);
        }
    }
}
