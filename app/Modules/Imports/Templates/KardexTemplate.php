<?php

namespace App\Modules\Imports\Templates;

use App\Modules\Academic\Models\Subject;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Jobs\RecomputeStudentProgress;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Kardex del SII (tarea 1.4): por número de control, clave de materia y periodo, la calificación, el resultado
 * y la oportunidad. El archivo identifica la fila con esas claves, pero la tabla guarda `student_id` y
 * `subject_id`: recordKey() los busca, y check() reporta el alumno o la materia que no existen. El kardex no
 * tiene `is_active`, así que no ofrece "desactivar faltantes".
 *
 * El periodo se guarda con el código del SII (`20251` Ene-Jun, `20252` verano, `20253` Ago-Dic); se aceptan
 * también `2025-1` y `2025/1`. Todavía no hay una exportación real: el resultado se reconoce con sus variantes
 * comunes (aprobado, AC, acreditada, NA, no acreditada, en curso) y se ajusta en la integración 1.16
 * (confirmado el 29 de octubre de 2026). Busca cada alumno y cada materia una vez por corrida: un kardex trae
 * cientos de miles de filas con los mismos alumnos y materias. Al terminar el lote despacha el recálculo del
 * progreso.
 */
class KardexTemplate extends ImportTemplate
{
    /** @var array<string, string|null> id de alumno por número de control, en esta corrida */
    private array $students = [];

    /** @var array<string, string|null> id de materia por clave, en esta corrida */
    private array $subjects = [];

    /**
     * Variantes del resultado, ya sin acentos, en minúsculas y con guiones bajos.
     */
    private const array RESULT_ALIASES = [
        'aprobado' => 'aprobada',
        'ac' => 'aprobada',
        'acreditada' => 'aprobada',
        'acreditado' => 'aprobada',
        'reprobado' => 'reprobada',
        'na' => 'reprobada',
        'no_acreditada' => 'reprobada',
        'no_acreditado' => 'reprobada',
        'en_curso' => 'cursando',
    ];

    public function key(): string
    {
        return 'kardex';
    }

    public function label(): string
    {
        return 'Kardex';
    }

    public function fields(): array
    {
        return [
            'control_number' => 'número de control',
            'subject_code' => 'clave de materia',
            'term_code' => 'periodo',
            'grade' => 'calificación',
            'result' => 'resultado',
            'attempt' => 'oportunidad',
        ];
    }

    public function naturalKey(): array
    {
        return ['control_number', 'subject_code', 'term_code'];
    }

    public function model(): string
    {
        return KardexRecord::class;
    }

    public function rules(): array
    {
        return [
            'control_number' => ['required', 'string', 'max:20'],
            'subject_code' => ['required', 'string'],
            'term_code' => ['required', 'string', 'regex:/^[0-9]{4}[123]$/'],
            'grade' => ['nullable', 'numeric', 'between:0,100'],
            'result' => ['required', Rule::enum(KardexResult::class)],
            'attempt' => ['required', 'integer', 'min:1'],
        ];
    }

    public function normalize(array $row): array
    {
        $row = parent::normalize($row);

        foreach (['control_number', 'subject_code', 'term_code'] as $field) {
            $row[$field] = self::asText($row[$field]);
        }

        $row['subject_code'] = is_string($row['subject_code']) ? Str::upper($row['subject_code']) : $row['subject_code'];

        if (is_string($row['term_code']) && preg_match('/^([0-9]{4})\D?([123])$/', $row['term_code'], $term)) {
            $row['term_code'] = $term[1].$term[2];
        }

        $row['grade'] = is_string($row['grade']) && is_numeric($row['grade']) ? (float) $row['grade'] : $row['grade'];
        $row['result'] = is_string($row['result']) ? $this->result($row['result']) : $row['result'];
        $row['attempt'] = is_string($row['attempt']) && ctype_digit($row['attempt']) ? (int) $row['attempt'] : $row['attempt'];

        return $row;
    }

    public function afterApply(ImportContext $context): void
    {
        RecomputeStudentProgress::dispatch();
    }

    protected function messages(): array
    {
        return [
            'term_code.regex' => 'El periodo debe ser el código del SII: año y 1, 2 o 3, como 20251.',
            'result.enum' => 'El resultado debe ser aprobada, reprobada, cursando o baja.',
            'attempt.min' => 'La oportunidad empieza en 1.',
        ];
    }

    protected function check(array $row, ImportContext $context, RowIssues $issues): RowIssues
    {
        if ($this->studentId($row['control_number']) === null) {
            $issues = $issues->withError('control_number', "No hay un alumno con el número de control {$row['control_number']}.");
        }

        if ($this->subjectId($row['subject_code']) === null) {
            $issues = $issues->withError('subject_code', "No hay una materia con la clave {$row['subject_code']}.");
        }

        return $issues->hasErrors() ? $issues : parent::check($row, $context, $issues);
    }

    protected function recordKey(array $row, ImportContext $context): ?array
    {
        $student = $this->studentId($row['control_number']);
        $subject = $this->subjectId($row['subject_code']);

        return $student === null || $subject === null
            ? null
            : ['student_id' => $student, 'subject_id' => $subject, 'term_code' => $row['term_code']];
    }

    protected function attributes(array $row, ImportContext $context): array
    {
        return [
            'grade' => $row['grade'],
            'result' => $row['result'],
            'attempt' => $row['attempt'],
        ];
    }

    /**
     * El valor de KardexResult que corresponde al texto del SII, o el texto tal cual si no lo conoce.
     */
    private function result(string $value): string
    {
        $slug = self::slug($value);

        return KardexResult::tryFrom($slug)?->value ?? self::RESULT_ALIASES[$slug] ?? $value;
    }

    private function studentId(string $controlNumber): ?string
    {
        return array_key_exists($controlNumber, $this->students)
            ? $this->students[$controlNumber]
            : $this->students[$controlNumber] = Student::query()->where('control_number', $controlNumber)->value('id');
    }

    private function subjectId(string $code): ?string
    {
        return array_key_exists($code, $this->subjects)
            ? $this->subjects[$code]
            : $this->subjects[$code] = Subject::query()->where('code', $code)->value('id');
    }
}
