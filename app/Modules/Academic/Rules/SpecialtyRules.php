<?php

namespace App\Modules\Academic\Rules;

use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de una especialidad que comparten CreateSpecialty, UpdateSpecialty y RemoveSpecialty
 * (docs/dominio/modelo-de-datos.md, `specialties`; reglas-de-negocio.md, "Especialidades"). Cada regla
 * rota es una ValidationException sobre su campo.
 *
 * - `code`: texto UTF-8 válido, en mayúsculas (como la importación de materias por plan), sin espacios,
 *   no vacío y único dentro de su plan, también contra las quitadas: la base lo exige igual.
 * - `name`: no vacío.
 * - Quitar: solo una especialidad sin materias en la retícula (confirmado el 29 de octubre de 2026).
 */
final class SpecialtyRules
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
     * Revisa una clave ya normalizada contra las especialidades del plan, también las quitadas.
     *
     * @throws ValidationException
     */
    public function ensureCodeIsFree(StudyPlan $plan, string $code): void
    {
        $taken = Specialty::withTrashed()
            ->where('study_plan_id', $plan->getKey())
            ->where('code', $code)
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
            'code' => "Ya existe una especialidad con la clave {$code} en este plan, aunque esté quitada.",
        ]);
    }

    /**
     * El nombre (NOT NULL en la base) no puede ser null ni quedar vacío. Con `$required` lo revisa aunque
     * falte en `$data` (alta); sin él, solo si viene (edición).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function ensureNameIsPresent(array $data, bool $required = false): void
    {
        if (! $required && ! array_key_exists('name', $data)) {
            return;
        }

        $name = $data['name'] ?? null;

        if (! is_string($name) || trim($name) === '') {
            throw ValidationException::withMessages(['name' => 'El nombre no puede quedar vacío.']);
        }
    }

    /**
     * S1: una especialidad con materias en la retícula (filas sin quitar) no se quita; se desactiva. Cuenta
     * también la fila cuya materia se borró del catálogo, aunque la retícula ya no la muestre: la fila sigue
     * apuntando a la especialidad, y quitarla la dejaría colgando de una borrada. Es a propósito, del lado
     * seguro; PlanGridEditorQuery cuenta igual para el número del chip y el botón deshabilitado.
     *
     * @throws ValidationException sobre `specialty` con cuántas materias tiene
     */
    public function ensureHasNoSubjects(Specialty $specialty): void
    {
        $subjects = $specialty->placements()->count();

        if ($subjects > 0) {
            throw ValidationException::withMessages(['specialty' => self::hasSubjects($subjects)]);
        }
    }

    /**
     * El motivo por el que no se quita una especialidad con `$subjects` materias; la pantalla lo muestra en
     * el botón deshabilitado.
     */
    public static function hasSubjects(int $subjects): string
    {
        return 'Tiene '.$subjects.($subjects === 1 ? ' materia' : ' materias').' en la retícula; una especialidad con materias no se quita.';
    }
}
