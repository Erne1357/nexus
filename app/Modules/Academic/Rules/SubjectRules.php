<?php

namespace App\Modules\Academic\Rules;

use App\Modules\Academic\Enums\SubjectKind;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Queries\KeyLookup;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de una materia que comparten CreateSubject y UpdateSubject
 * (docs/dominio/modelo-de-datos.md, `subjects`). Cada regla rota es una ValidationException sobre su
 * campo.
 *
 * - `code`: texto UTF-8 válido, en mayúsculas, sin espacios (también los de Unicode, como NBSP), no
 *   vacío y único entre todas las materias, también las borradas: la clave de una borrada sigue
 *   reservada.
 * - `theory_hours`, `practice_hours` y `credits`: no negativos. El tope de la columna (smallint) lo
 *   cuida el formulario; no es una regla.
 * - `kind`: un valor de SubjectKind o vacío, porque la exportación del SII no lo trae.
 * - `department_id`: un departamento que existe y no está borrado; uno inactivo vale, como en la
 *   importación de materias.
 */
final class SubjectRules
{
    private const array NON_NEGATIVE = [
        'theory_hours' => 'Las horas de teoría no pueden ser negativas.',
        'practice_hours' => 'Las horas de práctica no pueden ser negativas.',
        'credits' => 'Los créditos no pueden ser negativos.',
    ];

    /**
     * @throws ValidationException si no es UTF-8 válido o queda vacío
     */
    public function normalizeCode(string $code): string
    {
        $code = preg_replace('/\s+/u', '', $code);

        if ($code === null) {
            throw ValidationException::withMessages(['code' => 'La clave no es texto válido.']);
        }

        if ($code === '') {
            throw ValidationException::withMessages(['code' => 'La clave no puede quedar vacía ni ser solo espacios.']);
        }

        return Str::upper($code);
    }

    /**
     * Revisa una clave ya normalizada; `$except` es la materia que la conserva al editarse.
     *
     * @throws ValidationException
     */
    public function ensureCodeIsFree(string $code, ?Subject $except = null): void
    {
        $taken = Subject::withTrashed()
            ->where('code', $code)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();

        if ($taken) {
            throw $this->codeTaken($code);
        }
    }

    /**
     * El error de clave repetida, también para cuando la restricción única de la base gana la carrera
     * a ensureCodeIsFree().
     */
    public function codeTaken(string $code): ValidationException
    {
        return ValidationException::withMessages([
            'code' => "Ya existe una materia con la clave {$code}, aunque esté desactivada o borrada.",
        ]);
    }

    /**
     * Revisa las horas y los créditos que vengan en `$data`.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function ensureNotNegative(array $data): void
    {
        $errors = [];

        foreach (self::NON_NEGATIVE as $field => $message) {
            if (array_key_exists($field, $data) && $data[$field] < 0) {
                $errors[$field] = $message;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * El tipo como SubjectKind; vacío o null es una materia sin tipo.
     *
     * @throws ValidationException
     */
    public function kind(SubjectKind|string|null $kind): ?SubjectKind
    {
        if ($kind instanceof SubjectKind || $kind === null || $kind === '') {
            return $kind === '' ? null : $kind;
        }

        return SubjectKind::tryFrom($kind)
            ?? throw ValidationException::withMessages(['kind' => 'El tipo de materia no es válido.']);
    }

    /**
     * Dentro de la transacción de la Action, bloquea el departamento para compartir (`FOR SHARE`):
     * así DeleteDepartment, que lo bloquea para escribir, no lo borra mientras se le agrega una
     * materia, y si ya lo borró, aquí ya no existe.
     *
     * @throws ValidationException si el departamento no existe, está borrado o el id no es uuid
     */
    public function ensureDepartmentExists(string $departmentId): void
    {
        if (KeyLookup::find(Department::query()->sharedLock(), $departmentId) === null) {
            throw ValidationException::withMessages(['department_id' => 'El departamento no existe o está borrado.']);
        }
    }
}
