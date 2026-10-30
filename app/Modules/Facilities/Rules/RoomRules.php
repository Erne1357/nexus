<?php

namespace App\Modules\Facilities\Rules;

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de un salón y de sus bloqueos que comparten CreateRoom, UpdateRoom y AddRoomBlock
 * (docs/dominio/modelo-de-datos.md, `rooms` y `room_blocks`). Cada regla rota es una
 * ValidationException sobre su campo.
 *
 * - `code`: en mayúsculas, sin espacios, no vacío y único dentro del campus, también entre los borrados.
 * - `capacity` mayor que cero; `type` de RoomType; `building_id` de un edificio sin borrar del mismo
 *   campus; `department_id` (dueño), si viene, de un departamento sin borrar y activo (departamentos son globales).
 * - `features`: lista de textos recortados, sin vacíos ni repetidos (sin distinguir mayúsculas).
 * - Bloqueo: el día es uno de los del perfil del campus, las horas caen en su jornada (`day_start` a
 *   `day_end`), la de fin es posterior a la de inicio, el motivo no está vacío y el periodo, si viene, existe.
 */
final class RoomRules
{
    /** Los días de `room_blocks.day`, de 1 (lunes) a 6 (sábado). */
    public const array DAYS = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado'];

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
     * El nombre es opcional: vacío es null.
     */
    public function normalizeName(?string $name): ?string
    {
        $name = $this->trim($name ?? '');

        return $name === '' ? null : $name;
    }

    /**
     * @throws ValidationException
     */
    public function ensureCodeIsFree(Campus|string $campus, string $code, ?Room $except = null): void
    {
        $taken = Room::withTrashed()
            ->where('campus_id', $campus instanceof Campus ? $campus->getKey() : $campus)
            ->where('code', $code)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();

        if ($taken) {
            throw $this->codeTaken($code);
        }
    }

    public function codeTaken(string $code): ValidationException
    {
        return ValidationException::withMessages([
            'code' => "Ya existe un salón con la clave {$code} en este campus, aunque esté desactivado o borrado.",
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function ensureCapacity(int $capacity): void
    {
        if ($capacity <= 0) {
            throw ValidationException::withMessages(['capacity' => 'La capacidad debe ser mayor que cero.']);
        }
    }

    /**
     * @throws ValidationException
     */
    public function type(RoomType|string $type): RoomType
    {
        return $type instanceof RoomType
            ? $type
            : (RoomType::tryFrom($type) ?? throw ValidationException::withMessages(['type' => 'El tipo de salón no es válido.']));
    }

    /**
     * El edificio del campus, sin borrar; el id llega del navegador.
     *
     * @throws ValidationException
     */
    public function ensureBuildingBelongsToCampus(Campus|string $campus, string $buildingId): Building
    {
        $campusId = $campus instanceof Campus ? $campus->getKey() : $campus;
        $building = KeyLookup::find(Building::query(), $buildingId);

        if ($building === null || $building->campus_id !== $campusId) {
            throw ValidationException::withMessages(['building_id' => 'El edificio no es de este campus.']);
        }

        return $building;
    }

    /**
     * @throws ValidationException
     */
    public function ensureOwnerExists(?string $departmentId): void
    {
        if ($departmentId === null) {
            return;
        }

        $department = KeyLookup::find(Department::query(), $departmentId);

        if ($department === null) {
            throw ValidationException::withMessages(['department_id' => 'El departamento dueño no existe.']);
        }

        if (! $department->is_active) {
            throw ValidationException::withMessages(['department_id' => 'El departamento dueño está desactivado: elige uno activo.']);
        }
    }

    /**
     * Lista limpia de características: recortadas, con espacios internos colapsados, sin vacías ni repetidas
     * (sin distinguir mayúsculas) y en el orden en que llegaron.
     *
     * @param  array<array-key, mixed>  $features
     * @return list<string>
     */
    public function normalizeFeatures(array $features): array
    {
        $clean = [];

        foreach ($features as $feature) {
            $text = preg_replace('/[\s\x{00A0}]+/u', ' ', (string) $feature);
            $text = $this->trim($text ?? '');

            if ($text !== '' && ! in_array(Str::lower($text), array_map(Str::lower(...), $clean), true)) {
                $clean[] = $text;
            }
        }

        return $clean;
    }

    /**
     * @throws ValidationException
     */
    public function normalizeReason(string $reason): string
    {
        $reason = $this->trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'El motivo no puede quedar vacío.']);
        }

        return $reason;
    }

    /**
     * «9:00» y «09:00:00» como «09:00».
     *
     * @throws ValidationException
     */
    public function normalizeTime(string $time, string $field, string $label): string
    {
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/D', trim($time), $match) !== 1) {
            throw ValidationException::withMessages([$field => "La hora de {$label} debe tener el formato HH:MM."]);
        }

        return sprintf('%02d:%s', (int) $match[1], $match[2]);
    }

    /**
     * Día y horas del bloqueo dentro del perfil del campus; las horas ya normalizadas (HH:MM).
     *
     * @throws ValidationException
     */
    public function ensureBlockFitsCampus(CampusProfile $profile, int $day, string $startsAt, string $endsAt): void
    {
        if (! in_array($day, $profile->days, true)) {
            throw ValidationException::withMessages(['day' => 'El campus no tiene clases ese día.']);
        }

        if ($endsAt <= $startsAt) {
            throw ValidationException::withMessages(['ends_at' => 'La hora de fin debe ser posterior a la de inicio.']);
        }

        $window = "El bloqueo debe quedar dentro del horario del campus ({$profile->dayStart} a {$profile->dayEnd}).";

        if ($startsAt < $profile->dayStart) {
            throw ValidationException::withMessages(['starts_at' => $window]);
        }

        if ($endsAt > $profile->dayEnd) {
            throw ValidationException::withMessages(['ends_at' => $window]);
        }
    }

    /**
     * @throws ValidationException
     */
    public function ensureTermExists(?string $termId): void
    {
        if ($termId !== null && KeyLookup::find(Term::query(), $termId) === null) {
            throw ValidationException::withMessages(['term_id' => 'El periodo no existe.']);
        }
    }

    private function trim(string $text): string
    {
        return preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $text) ?? '';
    }
}
