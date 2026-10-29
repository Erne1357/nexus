<?php

namespace App\Modules\Imports\Templates;

use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\SubjectPrerequisite;
use App\Modules\Students\Jobs\RecomputeStudentProgress;
use Illuminate\Support\Str;

/**
 * Seriación del SII (tarea 1.9): clave de plan, materia y materia requisito, con un tipo opcional. Sin tipo es
 * seriación; si el archivo trae la columna, acepta seriación o correquisito sin importar acentos (confirmado el 29
 * de octubre de 2026). La seriación es de cada plan: la clave del archivo es la materia, su requisito y el plan.
 *
 * Es error si alguna de las dos materias no está en la retícula del plan, si una materia se pide a sí misma o si
 * una seriación cierra un ciclo con la que ya tiene el plan (confirmado el 29 de octubre de 2026). Un ciclo entre
 * renglones del mismo archivo se rechaza al aplicar, porque ApplyImportBatch vuelve a validar cada fila con lo
 * que las anteriores ya escribieron; por eso la seriación del plan se lee en cada fila. Los correquisitos no
 * bloquean, así que no forman ciclos.
 *
 * Como todo lo importado, una seriación borrada no se restaura. No ofrece "desactivar faltantes": quitar una
 * seriación cambia el avance de los alumnos y se hace a mano. Al terminar el lote recalcula el avance.
 */
class PrerequisitesTemplate extends ImportTemplate
{
    /** @var array<string, StudyPlan|null> plan por clave, en esta corrida */
    private array $plans = [];

    /** @var array<string, array<string, string>> plan → clave de materia → id, de su retícula, en esta corrida */
    private array $grids = [];

    public function key(): string
    {
        return 'prerequisites';
    }

    public function label(): string
    {
        return 'Seriación';
    }

    public function fields(): array
    {
        return [
            'plan' => 'clave de plan',
            'subject' => 'materia',
            'prerequisite' => 'materia requisito',
            'kind' => 'tipo',
        ];
    }

    public function naturalKey(): array
    {
        return ['subject', 'prerequisite', 'plan'];
    }

    public function model(): string
    {
        return SubjectPrerequisite::class;
    }

    public function deactivatesMissing(): bool
    {
        return false;
    }

    public function rules(): array
    {
        return [
            'plan' => ['required', 'string'],
            'subject' => ['required', 'string'],
            'prerequisite' => ['required', 'string'],
            'kind' => ['required', 'string', 'in:'.PrerequisiteKind::Seriacion->value.','.PrerequisiteKind::Correquisito->value],
        ];
    }

    protected function messages(): array
    {
        return ['kind.in' => 'El tipo debe ser seriación o correquisito.'];
    }

    public function normalize(array $row): array
    {
        $row = parent::normalize($row);

        foreach (['plan', 'subject', 'prerequisite'] as $field) {
            $row[$field] = self::asText($row[$field]);
            $row[$field] = is_string($row[$field]) ? Str::upper($row[$field]) : $row[$field];
        }

        $row['kind'] = match (true) {
            $row['kind'] === null => PrerequisiteKind::Seriacion->value,
            is_string($row['kind']) => PrerequisiteKind::tryFrom(self::slug($row['kind']))?->value ?? $row['kind'],
            default => $row['kind'],
        };

        return $row;
    }

    public function afterApply(ImportContext $context): void
    {
        RecomputeStudentProgress::dispatch();
    }

    protected function check(array $row, ImportContext $context, RowIssues $issues): RowIssues
    {
        $plan = $this->plan($row['plan']);

        if ($plan === null) {
            return $issues->withError('plan', "No hay un plan con la clave {$row['plan']}.");
        }

        $grid = $this->grid($plan);

        foreach (['subject', 'prerequisite'] as $field) {
            if (! isset($grid[$row[$field]])) {
                $issues = $issues->withError($field, "La materia {$row[$field]} no está en la retícula del plan {$row['plan']}.");
            }
        }

        if ($issues->hasErrors()) {
            return $issues;
        }

        if ($row['subject'] === $row['prerequisite']) {
            return $issues->withError('prerequisite', "La materia {$row['subject']} no puede pedirse a sí misma.");
        }

        $issues = parent::check($row, $context, $issues);

        if (! $issues->hasErrors() && $row['kind'] === PrerequisiteKind::Seriacion->value
            && $this->requiresTransitively($plan, $grid[$row['prerequisite']], $grid[$row['subject']])) {
            return $issues->withError('prerequisite', "Cierra un ciclo de seriación: {$row['prerequisite']} ya pide, en cadena, a {$row['subject']}.");
        }

        return $issues;
    }

    protected function recordKey(array $row, ImportContext $context): ?array
    {
        $plan = $this->plan($row['plan']);
        $grid = $plan === null ? [] : $this->grid($plan);

        if (! isset($grid[$row['subject']], $grid[$row['prerequisite']])) {
            return null;
        }

        return ['study_plan_id' => $plan->getKey(), 'subject_id' => $grid[$row['subject']], 'prerequisite_subject_id' => $grid[$row['prerequisite']]];
    }

    protected function attributes(array $row, ImportContext $context): array
    {
        return ['kind' => $row['kind']];
    }

    /**
     * Si la materia `$from` pide, directa o en cadena, a la materia `$to`, según la seriación que el plan tiene
     * hoy (sin correquisitos ni lo borrado).
     */
    private function requiresTransitively(StudyPlan $plan, string $from, string $to): bool
    {
        $requires = SubjectPrerequisite::query()
            ->where('study_plan_id', $plan->getKey())
            ->where('kind', PrerequisiteKind::Seriacion)
            ->toBase()
            ->get(['subject_id', 'prerequisite_subject_id'])
            ->groupBy('subject_id')
            ->map(fn ($rows): array => $rows->pluck('prerequisite_subject_id')->all());

        $seen = [];
        $pending = [$from];

        while ($pending !== []) {
            foreach ($requires->get(array_pop($pending), []) as $prerequisite) {
                if ($prerequisite === $to) {
                    return true;
                }

                if (! isset($seen[$prerequisite])) {
                    $seen[$prerequisite] = true;
                    $pending[] = $prerequisite;
                }
            }
        }

        return false;
    }

    private function plan(string $code): ?StudyPlan
    {
        return array_key_exists($code, $this->plans) ? $this->plans[$code] : $this->plans[$code] = StudyPlan::query()->where('code', $code)->first(['id']);
    }

    /**
     * Las materias de la retícula del plan (sin lo que se quitó ni las borradas del catálogo), por clave.
     *
     * @return array<string, string>
     */
    private function grid(StudyPlan $plan): array
    {
        return $this->grids[$plan->getKey()] ??= StudyPlanSubject::query()
            ->join('subjects', 'subjects.id', '=', 'study_plan_subjects.subject_id')
            ->whereNull('subjects.deleted_at')
            ->where('study_plan_subjects.study_plan_id', $plan->getKey())
            ->toBase()
            ->pluck('study_plan_subjects.subject_id', 'subjects.code')
            ->all();
    }
}
