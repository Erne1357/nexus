<?php

namespace App\Modules\Students\Queries;

use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Support\CredentialToken;
use App\Modules\Students\Support\StudentPhotos;

/**
 * Lo que muestra la página pública de verificación de la credencial (AC-20, tarea 1.27). Solo con un token auténtico y
 * vigente da datos, y solo los que pidió el usuario: foto, nombre, número de control, carrera y estatus («Estudiante» o
 * «Egresado»). Con el token vencido, alterado, de un alumno que ya no existe o que no tiene credencial vigente (baja), no
 * da ningún dato.
 */
final class CredentialVerificationQuery
{
    public function __construct(
        private readonly CredentialToken $tokens,
        private readonly StudentPhotos $photos,
    ) {}

    /**
     * @return array{state: 'valid', student: array{name: string, control_number: string, career: string, status: string, photo: ?string}}|array{state: 'expired'|'invalid'|'not_current'}
     */
    public function verify(string $token, int $now): array
    {
        $result = $this->tokens->verify($token, $now);

        if ($result === null) {
            return ['state' => 'invalid'];
        }

        if ($result === 'expired') {
            return ['state' => 'expired'];
        }

        $student = KeyLookup::find(Student::query()->with(['career' => fn ($query) => $query->withTrashed()]), $result['student']);

        if ($student === null) {
            return ['state' => 'invalid'];
        }

        $status = StudentCredentialQuery::statusLabel($student->status);

        if ($status === null) {
            return ['state' => 'not_current'];
        }

        $photo = $this->photos->read($student->control_number);

        return ['state' => 'valid', 'student' => [
            'name' => $student->name,
            'control_number' => $student->control_number,
            'career' => (string) $student->career?->name,
            'status' => $status,
            'photo' => $photo === null ? null : 'data:'.$photo['mime'].';base64,'.base64_encode($photo['bytes']),
        ]];
    }
}
