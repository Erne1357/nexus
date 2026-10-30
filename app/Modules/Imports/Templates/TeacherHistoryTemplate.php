<?php

namespace App\Modules\Imports\Templates;

use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Faculty\Enums\HistorySource;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherSubjectHistory;
use Illuminate\Support\Str;

/**
 * Historial docente del SII (tarea 1.13): una fila por grupo que el docente impartió, con número de empleado,
 * clave de materia, periodo, campus y grupo; los cinco son la clave natural (una fila por grupo, confirmado el
 * 30 de octubre de 2026, y la columna «grupo» es obligatoria, S13). La tabla guarda `teacher_id`, `subject_id`
 * y `campus_id`: recordKey() los busca, y check() reporta el docente, la materia o el campus que no existen.
 * El campus puede venir vacío (la tabla lo admite); si viene, tiene que existir. No tiene `is_active`, así que
 * no ofrece "desactivar faltantes".
 *
 * El periodo se guarda con el código del SII (`20241`); se aceptan también `2024-1` y `2024/1`. Lo importado
 * queda con `source = import`; reaplicar una fila que ya existe no cambia su origen. Busca cada docente, materia
 * y campus una vez por corrida.
 */
class TeacherHistoryTemplate extends ImportTemplate
{
    /** @var array<string, string|null> id de docente por número de empleado, en esta corrida */
    private array $teachers = [];

    /** @var array<string, string|null> id de materia por clave, en esta corrida */
    private array $subjects = [];

    /** @var array<string, string|null> id de campus por clave, en esta corrida */
    private array $campuses = [];

    public function key(): string
    {
        return 'teacher_history';
    }

    public function label(): string
    {
        return 'Historial docente';
    }

    public function fields(): array
    {
        return [
            'employee_number' => 'número de empleado',
            'subject_code' => 'clave de materia',
            'term_code' => 'periodo',
            'campus_code' => 'campus',
            'group_code' => 'grupo',
        ];
    }

    public function naturalKey(): array
    {
        return ['employee_number', 'subject_code', 'term_code', 'campus_code', 'group_code'];
    }

    public function model(): string
    {
        return TeacherSubjectHistory::class;
    }

    public function rules(): array
    {
        return [
            'employee_number' => ['required', 'string', 'max:20'],
            'subject_code' => ['required', 'string'],
            'term_code' => ['required', 'string', 'regex:/^[0-9]{4}[123]$/'],
            'campus_code' => ['nullable', 'string'],
            'group_code' => ['required', 'string', 'max:20'],
        ];
    }

    public function normalize(array $row): array
    {
        $row = parent::normalize($row);

        foreach (['employee_number', 'subject_code', 'term_code', 'campus_code', 'group_code'] as $field) {
            $row[$field] = self::asText($row[$field]);
        }

        foreach (['subject_code', 'campus_code', 'group_code'] as $field) {
            $row[$field] = is_string($row[$field]) ? Str::upper($row[$field]) : $row[$field];
        }

        if (is_string($row['term_code']) && preg_match('/^([0-9]{4})\D?([123])$/', $row['term_code'], $term)) {
            $row['term_code'] = $term[1].$term[2];
        }

        return $row;
    }

    public function deactivatesMissing(): bool
    {
        return false;
    }

    protected function messages(): array
    {
        return [
            'term_code.regex' => 'El periodo debe ser el código del SII: año y 1, 2 o 3, como 20251.',
        ];
    }

    protected function check(array $row, ImportContext $context, RowIssues $issues): RowIssues
    {
        if ($this->teacherId($row['employee_number']) === null) {
            $issues = $issues->withError('employee_number', "No hay un docente con el número de empleado {$row['employee_number']}.");
        }

        if ($this->subjectId($row['subject_code']) === null) {
            $issues = $issues->withError('subject_code', "No hay una materia con la clave {$row['subject_code']}.");
        }

        if ($row['campus_code'] !== null && $this->campusId($row['campus_code']) === null) {
            $issues = $issues->withError('campus_code', "No hay un campus con la clave {$row['campus_code']}.");
        }

        return $issues->hasErrors() ? $issues : parent::check($row, $context, $issues);
    }

    protected function recordKey(array $row, ImportContext $context): ?array
    {
        $teacher = $this->teacherId($row['employee_number']);
        $subject = $this->subjectId($row['subject_code']);
        $campus = $row['campus_code'] === null ? null : $this->campusId($row['campus_code']);

        if ($teacher === null || $subject === null || ($row['campus_code'] !== null && $campus === null)) {
            return null;
        }

        return [
            'teacher_id' => $teacher,
            'subject_id' => $subject,
            'term_code' => $row['term_code'],
            'campus_id' => $campus,
            'group_code' => $row['group_code'],
        ];
    }

    protected function attributes(array $row, ImportContext $context): array
    {
        return [];
    }

    protected function defaults(ImportContext $context): array
    {
        return ['source' => HistorySource::Import];
    }

    private function teacherId(string $employeeNumber): ?string
    {
        return array_key_exists($employeeNumber, $this->teachers)
            ? $this->teachers[$employeeNumber]
            : $this->teachers[$employeeNumber] = Teacher::query()->where('employee_number', $employeeNumber)->value('id');
    }

    private function subjectId(string $code): ?string
    {
        return array_key_exists($code, $this->subjects)
            ? $this->subjects[$code]
            : $this->subjects[$code] = Subject::query()->where('code', $code)->value('id');
    }

    private function campusId(string $code): ?string
    {
        return array_key_exists($code, $this->campuses)
            ? $this->campuses[$code]
            : $this->campuses[$code] = Campus::query()->where('code', $code)->value('id');
    }
}
