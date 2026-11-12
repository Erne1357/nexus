<?php

namespace App\Modules\Faculty\Rules;

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Faculty\Enums\TeacherDegree;
use App\Modules\Faculty\Models\Teacher;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de un docente y de sus adscripciones que comparten CreateTeacher, UpdateTeacher y AddAdscription
 * (docs/dominio/modelo-de-datos.md, `teachers` y `teacher_departments`). Cada regla rota es una
 * ValidationException sobre su campo.
 *
 * - `rfc`: texto UTF-8 válido, en mayúsculas y sin espacios (también los de Unicode, como NBSP), no vacío y único
 *   entre todos los docentes, también los borrados: el número de un borrado sigue reservado
 *   (reglas-de-negocio.md, "Reglas de datos").
 * - `name`: sin espacios a los lados y no vacío.
 * - `email`, `degree` y `hired_on`: opcionales; vacío es null. El grado, un valor de TeacherDegree.
 * - `institutional_email` (AC-06): opcional, en minúsculas, un correo del dominio `nexus.institutional_email_domain` y
 *   único sin importar mayúsculas entre todos los docentes, también los borrados.
 * - La adscripción: un departamento que existe y no está borrado (sin campus: el docente no es de un campus).
 */
final class TeacherRules
{
    /**
     * @throws ValidationException si no es UTF-8 válido o queda vacío
     */
    public function normalizeRfc(string $number): string
    {
        $number = TeacherRfc::normalize($number);

        if ($number === null) {
            throw ValidationException::withMessages(['rfc' => 'El RFC no es texto válido.']);
        }

        if ($number === '') {
            throw ValidationException::withMessages(['rfc' => 'El RFC no puede quedar vacío ni ser solo espacios.']);
        }

        return $number;
    }

    /**
     * Revisa un número ya normalizado, también contra los docentes borrados.
     *
     * @throws ValidationException
     */
    public function ensureRfcIsFree(string $number): void
    {
        if (Teacher::withTrashed()->where('rfc', $number)->exists()) {
            throw $this->rfcTaken($number);
        }
    }

    /**
     * El error de número repetido, también para cuando la restricción única de la base gana la carrera a
     * ensureRfcIsFree().
     */
    public function rfcTaken(string $number): ValidationException
    {
        return ValidationException::withMessages([
            'rfc' => "Ya existe un docente con el RFC {$number}, aunque esté inactivo o borrado.",
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function name(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'El nombre no puede quedar vacío.']);
        }

        return $name;
    }

    /**
     * Un texto opcional sin espacios a los lados; vacío es null.
     */
    public function optional(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * El correo institucional sin espacios y en minúsculas; vacío es null. Debe ser del dominio institucional (AC-06).
     *
     * @throws ValidationException
     */
    public function institutionalEmail(?string $email): ?string
    {
        $email = $this->optional($email);

        if ($email === null) {
            return null;
        }

        $email = mb_strtolower($email);
        $domain = mb_strtolower((string) config('nexus.institutional_email_domain'));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || ! str_ends_with($email, "@{$domain}")) {
            throw ValidationException::withMessages(['institutional_email' => "El correo institucional debe terminar en @{$domain}."]);
        }

        return $email;
    }

    /**
     * Que ningún otro docente, aunque esté borrado, tenga ya ese correo institucional.
     *
     * @throws ValidationException
     */
    public function ensureInstitutionalEmailIsFree(string $email, Teacher $teacher): void
    {
        if (Teacher::withTrashed()->whereRaw('lower(institutional_email) = ?', [$email])->whereKeyNot($teacher->getKey())->exists()) {
            throw $this->institutionalEmailTaken($email);
        }
    }

    /**
     * El error de correo repetido, también para cuando la restricción única de la base gana la carrera.
     */
    public function institutionalEmailTaken(string $email): ValidationException
    {
        return ValidationException::withMessages(['institutional_email' => "Otro docente ya tiene el correo institucional {$email}."]);
    }

    /**
     * El grado como TeacherDegree; vacío o null es sin grado.
     *
     * @throws ValidationException
     */
    public function degree(TeacherDegree|string|null $degree): ?TeacherDegree
    {
        if ($degree instanceof TeacherDegree || $degree === null || $degree === '') {
            return $degree === '' ? null : $degree;
        }

        return TeacherDegree::tryFrom($degree)
            ?? throw ValidationException::withMessages(['degree' => 'El grado no es válido.']);
    }

    /**
     * Dentro de la transacción de la Action, bloquea el departamento para compartir (`FOR SHARE`), como
     * SubjectRules: DeleteDepartment, que lo bloquea para escribir, no lo borra mientras se le adscribe un docente.
     *
     * @throws ValidationException si el departamento no existe o está borrado
     */
    public function lockDepartment(Department $department): Department
    {
        return KeyLookup::find(Department::query()->sharedLock(), $department->getKey())
            ?? throw ValidationException::withMessages(['department_id' => 'El departamento no existe o está borrado.']);
    }

    /**
     * Los valores de `$fields` como van a la bitácora: el grado por su valor y la fecha de ingreso como `Y-m-d`.
     *
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    public function logged(Teacher $teacher, array $fields): array
    {
        $values = [];

        foreach ($fields as $field) {
            $values[$field] = match ($field) {
                'degree' => $teacher->degree?->value,
                'hired_on' => $teacher->hired_on?->toDateString(),
                default => $teacher->getAttribute($field),
            };
        }

        return $values;
    }
}
