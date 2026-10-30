<?php

namespace App\Modules\Imports\Templates;

use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Faculty\Rules\TeacherRules;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Plazas del SII (tarea 10): número de empleado, tipo de plaza, horas base y vigencia (`dd/mm/aaaa`). La clave es
 * el docente, el tipo y «desde»; el archivo trae el número de empleado y la tabla guarda `teacher_id`, que
 * `recordKey()` traduce. Un docente puede tener varias plazas vigentes a la vez, así que dos del mismo docente
 * con otro tipo u otro «desde» no son repetidas.
 *
 * El tipo se reconoce por el nombre del SII, sin importar acentos ni mayúsculas (`TIEMPO COMPLETO`, `3/4`,
 * `MEDIO TIEMPO`, `ASIGNATURA`, `HONORARIOS`). Las horas deben cuadrar con el tipo (S3, supuesto por confirmar)
 * y «hasta» no puede ser anterior a «desde». Un docente que no existe o está borrado es error.
 */
class PositionsTemplate extends ImportTemplate
{
    use ParsesSiiDates;

    /** @var array<string, Teacher|null> docente por número de empleado, en esta corrida */
    private array $teachers = [];

    public function key(): string
    {
        return 'positions';
    }

    public function label(): string
    {
        return 'Plazas';
    }

    public function fields(): array
    {
        return [
            'employee_number' => 'número de empleado',
            'kind' => 'tipo de plaza',
            'base_hours' => 'horas base',
            'valid_from' => 'desde',
            'valid_to' => 'hasta',
        ];
    }

    public function naturalKey(): array
    {
        return ['employee_number', 'kind', 'valid_from'];
    }

    public function model(): string
    {
        return TeacherPosition::class;
    }

    public function rules(): array
    {
        return [
            'employee_number' => ['required', 'string', 'max:20'],
            'kind' => ['required', Rule::enum(PositionKind::class)],
            'base_hours' => ['required', 'integer', 'min:0', 'max:'.PositionKind::MAX_HOURS],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function normalize(array $row): array
    {
        $row = parent::normalize($row);
        $row['employee_number'] = self::asText($row['employee_number']);

        if (is_string($row['employee_number'])) {
            try {
                $row['employee_number'] = app(TeacherRules::class)->normalizeEmployeeNumber($row['employee_number']);
            } catch (ValidationException) {
                // Texto que no es UTF-8 válido: validate() lo reporta.
            }
        }

        $row['kind'] = is_string($row['kind']) ? ($this->kind($row['kind'])?->value ?? $row['kind']) : $row['kind'];
        $row['base_hours'] = match (true) {
            is_string($row['base_hours']) && ctype_digit($row['base_hours']) => (int) $row['base_hours'],
            is_float($row['base_hours']) && floor($row['base_hours']) === $row['base_hours'] => (int) $row['base_hours'],
            default => $row['base_hours'],
        };
        $row['valid_from'] = self::siiDate($row['valid_from']);
        $row['valid_to'] = self::siiDate($row['valid_to']);

        return $row;
    }

    protected function check(array $row, ImportContext $context, RowIssues $issues): RowIssues
    {
        $issues = parent::check($row, $context, $issues);
        $teacher = $this->teacher($row['employee_number']);

        if ($teacher === null) {
            $issues = $issues->withError('employee_number', "No hay un docente con el número de empleado {$row['employee_number']}.");
        } elseif ($teacher->trashed()) {
            $issues = $issues->withError('employee_number', "El docente {$row['employee_number']} está borrado.");
        }

        $kind = PositionKind::from($row['kind']);

        if (! $kind->allows($row['base_hours'])) {
            $expected = $kind->fixedHours() === null ? 'de 1 a '.PositionKind::MAX_HOURS : (string) $kind->fixedHours();
            $issues = $issues->withError('base_hours', "Una plaza de {$kind->label()} tiene {$expected} horas base, no {$row['base_hours']}.");
        }

        if ($row['valid_from'] !== null && $row['valid_to'] !== null && $row['valid_to'] < $row['valid_from']) {
            $issues = $issues->withError('valid_to', 'La vigencia termina antes de empezar: «hasta» es anterior a «desde».');
        }

        return $issues;
    }

    protected function recordKey(array $row, ImportContext $context): ?array
    {
        $teacher = $this->teacher($row['employee_number']);

        if ($teacher === null || $teacher->trashed()) {
            return null;
        }

        return ['teacher_id' => $teacher->getKey(), 'kind' => $row['kind'], 'valid_from' => $row['valid_from']];
    }

    protected function attributes(array $row, ImportContext $context): array
    {
        return ['base_hours' => $row['base_hours'], 'valid_to' => $row['valid_to']];
    }

    private function teacher(string $number): ?Teacher
    {
        return array_key_exists($number, $this->teachers)
            ? $this->teachers[$number]
            : $this->teachers[$number] = Teacher::withTrashed()->where('employee_number', $number)->first(['id', 'deleted_at']);
    }

    private function kind(string $name): ?PositionKind
    {
        return match (self::slug($name)) {
            'tiempo_completo' => PositionKind::TiempoCompleto,
            '3_4', '3_4_de_tiempo', 'tres_cuartos', 'tres_cuartos_de_tiempo' => PositionKind::TresCuartos,
            'medio_tiempo' => PositionKind::MedioTiempo,
            'asignatura' => PositionKind::Asignatura,
            'honorarios' => PositionKind::Honorarios,
            default => null,
        };
    }
}
