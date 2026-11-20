<?php

namespace App\Modules\Students\Queries;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Queries\CredentialQuery;
use App\Modules\Students\Models\Student;

/**
 * La credencial del alumno (pestaña «Credencial» de «Mis datos», AC-15): nombre, número de control, carrera, campus, el
 * periodo activo y las iniciales que ocupan el lugar de la foto. Las iniciales y el periodo salen de CredentialQuery, la
 * credencial de la banda y del login, para que las tres digan lo mismo. Aún no lleva foto ni código QR: no hay datos.
 */
final class StudentCredentialQuery
{
    public function __construct(private readonly CredentialQuery $credentials) {}

    /**
     * @return array{name: string, control_number: string, career: string, campus: string, term: ?string, initials: string}
     */
    public function of(Actor $context, Student $student): array
    {
        $credential = $this->credentials->of($context);

        return [
            'name' => $student->name,
            'control_number' => $student->control_number,
            'career' => $student->career->name,
            'campus' => $student->campus->name,
            'term' => $credential['term'],
            'initials' => $credential['initials'],
        ];
    }
}
