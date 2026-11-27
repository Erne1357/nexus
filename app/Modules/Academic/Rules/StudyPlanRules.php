<?php

namespace App\Modules\Academic\Rules;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Queries\KeyLookup;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de un plan de estudios que comparten CreateStudyPlan y UpdateStudyPlan
 * (docs/dominio/modelo-de-datos.md, `study_plans`). Cada regla rota es una ValidationException sobre
 * su campo.
 *
 * - `code`: texto UTF-8 válido, en mayúsculas, sin espacios, no vacío y único entre todos los planes,
 *   también los borrados: los alumnos referencian planes viejos y su clave sigue reservada.
 * - `career_id`: una carrera que existe y no está borrada (una inactiva vale).
 * - `semesters_count`: mayor que cero o vacío; `total_credits`: no negativo o vacío.
 */
final class StudyPlanRules
{
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
     * Revisa una clave ya normalizada; `$except` es el plan que la conserva al editarse.
     *
     * @throws ValidationException
     */
    public function ensureCodeIsFree(string $code, ?StudyPlan $except = null): void
    {
        $taken = StudyPlan::withTrashed()
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
            'code' => "Ya existe un plan con la clave {$code}, aunque esté desactivado o borrado.",
        ]);
    }

    /**
     * El nombre y la versión (NOT NULL en la base) no pueden ser null ni quedar vacíos. Con
     * `$required` revisa los dos aunque falten en `$data` (alta); sin él, solo los que vengan (edición).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function ensureTextIsPresent(array $data, bool $required = false): void
    {
        $labels = ['name' => 'El nombre', 'version' => 'La versión'];
        $errors = [];

        foreach ($labels as $field => $label) {
            if (! $required && ! array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field] ?? null;

            if (! is_string($value) || trim($value) === '') {
                $errors[$field] = "{$label} no puede quedar vacío.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Revisa los semestres y los créditos que vengan en `$data`; null es «sin capturar».
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function ensureNumbersAreValid(array $data): void
    {
        $errors = [];

        if (isset($data['semesters_count']) && $data['semesters_count'] <= 0) {
            $errors['semesters_count'] = 'Los semestres deben ser más de cero o quedar vacíos.';
        }

        if (isset($data['total_credits']) && $data['total_credits'] < 0) {
            $errors['total_credits'] = 'Los créditos no pueden ser negativos.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Dentro de la transacción de la Action, bloquea la carrera para compartir (`FOR SHARE`): así
     * DeleteCareer no la borra mientras se le agrega o se le pasa un plan.
     *
     * @throws ValidationException sobre `career_id` si no existe, está borrada o el id no es uuid
     */
    public function career(string $id): Career
    {
        return KeyLookup::find(Career::query()->sharedLock(), $id)
            ?? throw ValidationException::withMessages(['career_id' => 'La carrera no existe o está borrada.']);
    }
}
