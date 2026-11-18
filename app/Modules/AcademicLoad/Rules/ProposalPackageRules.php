<?php

namespace App\Modules\AcademicLoad\Rules;

use App\Modules\Demand\Enums\ForecastMethod;
use App\Modules\Demand\Models\DemandForecast;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Lo que valen los paquetes y el nuevo ingreso de la propuesta de carrera (reglas-de-negocio.md, «Propuesta de carrera»;
 * tarea 5.6b). Puro, sin base de datos.
 *
 * - Cuántos paquetes abre un campus de cada uno: de 0 a 30 (supuesto por confirmar, como los grupos de una fila).
 * - El nombre de un paquete es opcional, de hasta 60 caracteres; vacío es nulo.
 * - Nuevo ingreso esperado sugerido de una columna: el `new_students` de la proyección en las filas de primer semestre
 *   (`first_semester`) de ese campus; si varían, el máximo (supuesto por confirmar). Sin esas filas, no hay sugerido.
 * - Paquetes sugeridos: `ceil(nuevo ingreso / tamaño objetivo normal)` y todos al primer paquete (el de menor posición); a
 *   los demás, 0 (supuesto por confirmar).
 */
final class ProposalPackageRules
{
    public const int MAX_PACKAGE_COUNT = 30;

    public const int MAX_NAME_LENGTH = 60;

    /**
     * @throws ValidationException sobre `count`
     */
    public function ensureCount(int $count): void
    {
        if ($count < 0 || $count > self::MAX_PACKAGE_COUNT) {
            throw ValidationException::withMessages(['count' => 'Los paquetes que se abren van de 0 a '.self::MAX_PACKAGE_COUNT.'.']);
        }
    }

    /**
     * @throws ValidationException sobre `new_students`
     */
    public function ensureNewStudents(int $newStudents): void
    {
        if ($newStudents < 0 || $newStudents > ProposalItemRules::MAX_EXPECTED_STUDENTS) {
            throw ValidationException::withMessages(['new_students' => 'El nuevo ingreso va de 0 a '.ProposalItemRules::MAX_EXPECTED_STUDENTS.'.']);
        }
    }

    /**
     * @throws ValidationException sobre `name`
     */
    public function normalizeName(?string $name): ?string
    {
        $name = $name === null || trim($name) === '' ? null : trim($name);

        if ($name !== null && mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw ValidationException::withMessages(['name' => 'El nombre lleva hasta '.self::MAX_NAME_LENGTH.' caracteres.']);
        }

        return $name;
    }

    /**
     * El nuevo ingreso sugerido de una columna, de la proyección de la carrera (DemandForecastsQuery::forCareer).
     *
     * @param  Collection<string, Collection<string, DemandForecast>>  $forecasts  id de materia => (id de campus, o '') => fila
     */
    public function suggestedNewStudents(Collection $forecasts, ?string $campusId): ?int
    {
        $values = $forecasts
            ->map(fn (Collection $rows): ?DemandForecast => $rows->get($campusId ?? ''))
            ->filter(fn (?DemandForecast $row): bool => $row !== null && $row->method === ForecastMethod::FirstSemester && $row->new_students !== null)
            ->map(fn (DemandForecast $row): int => $row->new_students);

        return $values->isEmpty() ? null : $values->max();
    }

    /**
     * Los paquetes que cubren el nuevo ingreso: el sugerido del primer paquete.
     */
    public function suggestedPackages(int $newStudents, int $groupSize): int
    {
        return $newStudents <= 0 ? 0 : (int) ceil($newStudents / max(1, $groupSize));
    }
}
