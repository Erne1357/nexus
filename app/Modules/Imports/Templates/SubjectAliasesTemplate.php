<?php

namespace App\Modules\Imports\Templates;

use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\SubjectAlias;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

/**
 * Claves del SII de las materias (R11 de la migración del SII): cada renglón deja la clave con que el SII conoce una
 * materia como alias (`subject_aliases`) de la materia de Nexus, con el plan donde se vio. La escribe el paso
 * `materias` después del lote de materias, también para las claves que se unificaron en una clave TecNM (D8), para
 * que el kárdex y los grupos del SII encuentren su materia.
 *
 * La materia y el plan, si viene, deben existir por su clave. Un alias que ya apunta a otra materia es error; a la
 * misma, no hace nada: no cambia su plan. Los alias no tienen `is_active` ni borrado lógico, así que la plantilla
 * nunca desactiva (supuesto por confirmar, R7).
 */
class SubjectAliasesTemplate extends ImportTemplate
{
    public function key(): string
    {
        return 'subject_aliases';
    }

    public function label(): string
    {
        return 'Claves del SII de materias';
    }

    public function fields(): array
    {
        return [
            'code' => 'clave del SII',
            'subject' => 'materia',
            'plan' => 'plan',
        ];
    }

    public function naturalKey(): array
    {
        return ['code'];
    }

    public function model(): string
    {
        return SubjectAlias::class;
    }

    public function deactivatesMissing(): bool
    {
        return false;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20'],
            'subject' => ['required', 'string', 'max:20'],
            'plan' => ['nullable', 'string'],
        ];
    }

    public function normalize(array $row): array
    {
        $row = parent::normalize($row);

        foreach (['code', 'subject', 'plan'] as $field) {
            $row[$field] = self::asText($row[$field]);
            $row[$field] = is_string($row[$field]) ? Str::upper($row[$field]) : $row[$field];
        }

        return $row;
    }

    /**
     * Da de alta el alias si falta; si ya es de la misma materia lo deja igual.
     *
     * @throws LogicException si el alias es de otra materia: validate() ya lo reporta como error
     */
    public function apply(array $row, ImportContext $context): Model
    {
        $subject = Subject::query()->where('code', $row['subject'])->firstOrFail(['id']);
        $plan = $row['plan'] === null ? null : StudyPlan::query()->where('code', $row['plan'])->firstOrFail(['id']);

        $alias = SubjectAlias::query()->firstOrCreate(
            ['code' => $row['code']],
            ['subject_id' => $subject->getKey(), 'study_plan_id' => $plan?->getKey()],
        );

        if ($alias->subject_id !== $subject->getKey()) {
            throw new LogicException("La clave del SII {$row['code']} ya es de otra materia: validate() lo reporta como error.");
        }

        return $alias;
    }

    protected function check(array $row, ImportContext $context, RowIssues $issues): RowIssues
    {
        $subject = Subject::query()->where('code', $row['subject'])->first(['id']);

        if ($subject === null) {
            $issues = $issues->withError('subject', "No hay una materia con la clave {$row['subject']}.");
        }

        if ($row['plan'] !== null && ! StudyPlan::query()->where('code', $row['plan'])->exists()) {
            $issues = $issues->withError('plan', "No hay un plan con la clave {$row['plan']}.");
        }

        $owner = SubjectAlias::query()->where('code', $row['code'])->value('subject_id');

        if ($owner !== null && $owner !== $subject?->getKey()) {
            $issues = $issues->withError('code', "La clave del SII {$row['code']} ya es de otra materia.");
        }

        return $issues;
    }
}
