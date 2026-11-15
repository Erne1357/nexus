<?php

namespace App\Modules\Faculty\Livewire\Concerns;

use App\Modules\Faculty\Models\Teacher;
use Illuminate\Support\Facades\Gate;

/**
 * El correo institucional del docente (AC-06) en los formularios de Faculty, el mismo en «Editar docente» de la lista y
 * en «Editar datos» de la ficha, con su campo en el parcial `faculty::teachers.institutional-email-field`: quién lo
 * cambia lo decide TeacherPolicy (`updateInstitutionalEmail`) y la forma, UpdateTeacher (TeacherRules). Si no cambió,
 * no se pregunta: quien no lo puede cambiar guarda los demás datos.
 */
trait EditsInstitutionalEmail
{
    public string $institutional_email = '';

    /**
     * Por qué no puede cambiar el correo institucional, o null si puede: de un docente con otro puesto, solo el admin;
     * si no, solo el jefe de su departamento y el admin.
     */
    protected function institutionalEmailReason(Teacher $teacher): ?string
    {
        return match (true) {
            Gate::allows('updateInstitutionalEmail', $teacher) => null,
            Gate::allows('inviteAccess', $teacher) => 'Tiene otro puesto: su correo institucional solo lo cambia el administrador.',
            default => 'Solo su jefe de departamento o el administrador lo capturan.',
        };
    }

    /**
     * Lo que se manda a UpdateTeacher del correo institucional capturado: nada si no cambió (sin importar mayúsculas ni
     * espacios), el correo si cambió y le toca, o null con el motivo en el campo si cambió y no le toca.
     *
     * @return array{institutional_email?: string}|null
     */
    protected function institutionalEmailChange(Teacher $teacher): ?array
    {
        if (mb_strtolower(trim($this->institutional_email)) === (string) $teacher->institutional_email) {
            return [];
        }

        $reason = $this->institutionalEmailReason($teacher);

        if ($reason !== null) {
            $this->addError('institutional_email', $reason);

            return null;
        }

        return ['institutional_email' => $this->institutional_email];
    }
}
