<?php

namespace App\Modules\Imports\Templates;

use App\Modules\Faculty\Enums\EvaluationKind;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherEvaluation;
use Illuminate\Validation\Rule;

/**
 * Evaluación docente del SII (tarea 1.13): por RFC, periodo y tipo (`estudiantil` o
 * `departamental`, por nombre), el puntaje. La clave natural es esos tres. El puntaje es de 1 a 5 y se acepta de
 * 0 a 5 con hasta dos decimales; un valor fuera de ese rango es error (confirmado el 30 de octubre de 2026). Se
 * lee con coma o con punto (`4,5` es 4.5). La tabla guarda `teacher_id`: recordKey() lo busca, y check() reporta
 * el docente que no existe. No tiene `is_active`, así que no ofrece "desactivar faltantes".
 *
 * El periodo es el código del SII (`20241`); se aceptan también `2024-1` y `2024/1`.
 */
class EvaluationsTemplate extends ImportTemplate
{
    use ChecksTeacherRfc;

    /** @var array<string, string|null> id de docente por RFC, en esta corrida */
    private array $teachers = [];

    public function key(): string
    {
        return 'evaluations';
    }

    public function label(): string
    {
        return 'Evaluación docente';
    }

    public function fields(): array
    {
        return [
            'rfc' => 'RFC',
            'term_code' => 'periodo',
            'kind' => 'tipo',
            'score' => 'puntaje',
        ];
    }

    public function naturalKey(): array
    {
        return ['rfc', 'term_code', 'kind'];
    }

    public function model(): string
    {
        return TeacherEvaluation::class;
    }

    public function rules(): array
    {
        return [
            'rfc' => $this->rfcRules(),
            'term_code' => ['required', 'string', 'regex:/^[0-9]{4}[123]$/'],
            'kind' => ['required', Rule::enum(EvaluationKind::class)],
            'score' => ['bail', 'required', 'numeric', 'between:0,5', 'decimal:0,2'],
        ];
    }

    public function normalize(array $row): array
    {
        $row = parent::normalize($row);

        foreach (['rfc', 'term_code'] as $field) {
            $row[$field] = self::asText($row[$field]);
        }

        $row['rfc'] = self::normalizedRfc($row['rfc']);

        if (is_string($row['term_code']) && preg_match('/^([0-9]{4})\D?([123])$/', $row['term_code'], $term)) {
            $row['term_code'] = $term[1].$term[2];
        }

        $row['kind'] = is_string($row['kind']) ? (EvaluationKind::tryFrom(self::slug($row['kind']))?->value ?? $row['kind']) : $row['kind'];

        if (is_string($row['score'])) {
            $score = str_replace(',', '.', $row['score']);
            $row['score'] = is_numeric($score) ? (float) $score : $row['score'];
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
            'kind.enum' => 'El tipo debe ser estudiantil o departamental.',
            'score.numeric' => 'El puntaje debe estar entre 0 y 5.',
            'score.between' => 'El puntaje debe estar entre 0 y 5.',
            'score.decimal' => 'El puntaje admite hasta dos decimales.',
        ];
    }

    protected function check(array $row, ImportContext $context, RowIssues $issues): RowIssues
    {
        $issues = $this->warnWithoutHomoclave($issues, $row['rfc']);

        if ($this->teacherId($row['rfc']) === null) {
            $issues = $issues->withError('rfc', "No hay un docente con el RFC {$row['rfc']}.");
        }

        return $issues->hasErrors() ? $issues : parent::check($row, $context, $issues);
    }

    protected function recordKey(array $row, ImportContext $context): ?array
    {
        $teacher = $this->teacherId($row['rfc']);

        return $teacher === null
            ? null
            : ['teacher_id' => $teacher, 'term_code' => $row['term_code'], 'kind' => $row['kind']];
    }

    protected function attributes(array $row, ImportContext $context): array
    {
        return ['score' => $row['score']];
    }

    protected function defaults(ImportContext $context): array
    {
        return ['source' => 'SII'];
    }

    private function teacherId(string $rfc): ?string
    {
        return array_key_exists($rfc, $this->teachers)
            ? $this->teachers[$rfc]
            : $this->teachers[$rfc] = Teacher::query()->where('rfc', $rfc)->value('id');
    }
}
