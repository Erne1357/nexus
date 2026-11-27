<?php

namespace App\Modules\Imports\Templates;

use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanPrerequisitePending;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\StudyPlanSubjectPending;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * «Seriación por validar» (migración de la seriación del SII; decisión del usuario del 10 de noviembre de 2026,
 * confirmada): un requisito con una materia en «Por validar» del plan no puede entrar a `subject_prerequisites`, que
 * pide las dos materias en la retícula. Se guarda aparte, ligado al plan (`study_plan_prerequisite_pendings`), y entra
 * solo cuando Servicios Escolares le asigna semestre a la materia, y a la otra si también esperaba
 * (ApplyWaitingPrerequisites, desde ResolvePendingPlacement). Los campos son los de `prerequisites` más la retícula del
 * SII de la que salió (`sii_grid`); el tipo se reconoce igual (sin tipo es seriación).
 *
 * Es error si el plan no existe, si alguna materia no está en la retícula ni en «Por validar» del plan (un pendiente
 * resuelto ya está en la retícula), si una materia se pide a sí misma, o si las dos ya están en la retícula: entonces
 * es seriación y va por `prerequisites`. No revisa ciclos: se revisan al aplicarse. Repetir el renglón no duplica: uno
 * sin aplicar toma el tipo y la retícula del SII del archivo, y uno que ya entró a la seriación no se toca (avisa). No
 * ofrece «desactivar faltantes» ni recalcula el avance: lo que espera todavía no es seriación.
 */
class PendingPrerequisitesTemplate extends ImportTemplate
{
    /** @var array<string, StudyPlan|null> plan por clave, en esta corrida */
    private array $plans = [];

    /** @var array<string, array{grid: array<string, string>, pending: array<string, string>}> plan → clave → id de materia, en esta corrida */
    private array $places = [];

    public function key(): string
    {
        return 'pending_prerequisites';
    }

    public function label(): string
    {
        return 'Seriación por validar';
    }

    public function fields(): array
    {
        return [
            'plan' => 'clave de plan',
            'subject' => 'materia',
            'prerequisite' => 'materia requisito',
            'kind' => 'tipo',
            'sii_grid' => 'retícula del sii',
        ];
    }

    public function naturalKey(): array
    {
        return ['subject', 'prerequisite', 'plan'];
    }

    public function model(): string
    {
        return StudyPlanPrerequisitePending::class;
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
            'kind' => ['string', Rule::enum(PrerequisiteKind::class)],
            'sii_grid' => ['nullable', 'string', 'max:50'],
        ];
    }

    protected function messages(): array
    {
        return ['kind.enum' => 'El tipo debe ser seriación o correquisito.'];
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

    /**
     * Da de alta el requisito que espera o, si no ha entrado a la seriación, le escribe el tipo y la retícula del SII.
     * Uno que ya entró se devuelve sin tocarlo.
     */
    public function apply(array $row, ImportContext $context): Model
    {
        $key = $this->recordKey($row, $context) ?? throw new LogicException("La plantilla {$this->key()} no encontró a qué registro apunta una fila válida.");
        $waiting = StudyPlanPrerequisitePending::query()->where($key)->first()
            ?? (new StudyPlanPrerequisitePending)->forceFill([...$key, 'source' => 'sii']);

        if ($waiting->applied_at === null) {
            $waiting->fill(['kind' => $row['kind'], 'sii_grid' => $row['sii_grid'] ?? $waiting->sii_grid])->save();
        }

        return $waiting;
    }

    protected function check(array $row, ImportContext $context, RowIssues $issues): RowIssues
    {
        $plan = $this->plan($row['plan']);

        if ($plan === null) {
            return $issues->withError('plan', "No hay un plan con la clave {$row['plan']}.");
        }

        if (! $plan->is_active) {
            $issues = $issues->withWarning('plan', "El plan {$row['plan']} está inactivo.");
        }

        $places = $this->places($plan);

        foreach (['subject', 'prerequisite'] as $field) {
            if (! isset($places['grid'][$row[$field]]) && ! isset($places['pending'][$row[$field]])) {
                $issues = $issues->withError($field, "La materia {$row[$field]} no está en la retícula ni en «Por validar» del plan {$row['plan']}.");
            }
        }

        if ($issues->hasErrors()) {
            return $issues;
        }

        if ($row['subject'] === $row['prerequisite']) {
            return $issues->withError('prerequisite', "La materia {$row['subject']} no puede pedirse a sí misma.");
        }

        if (isset($places['grid'][$row['subject']], $places['grid'][$row['prerequisite']])) {
            return $issues->withError('subject', "Las dos materias ya están en la retícula del plan {$row['plan']}: impórtalo como seriación.");
        }

        $issues = parent::check($row, $context, $issues);
        $key = $this->recordKey($row, $context);

        if ($key !== null && StudyPlanPrerequisitePending::query()->where($key)->whereNotNull('applied_at')->exists()) {
            $issues = $issues->withWarning('subject', "El requisito {$row['prerequisite']} de {$row['subject']} ya entró a la seriación del plan {$row['plan']}: no se cambia.");
        }

        return $issues;
    }

    protected function recordKey(array $row, ImportContext $context): ?array
    {
        $plan = $this->plan((string) $row['plan']);

        if ($plan === null) {
            return null;
        }

        $places = $this->places($plan);
        $subject = $places['grid'][$row['subject']] ?? $places['pending'][$row['subject']] ?? null;
        $prerequisite = $places['grid'][$row['prerequisite']] ?? $places['pending'][$row['prerequisite']] ?? null;

        if ($subject === null || $prerequisite === null) {
            return null;
        }

        return ['study_plan_id' => $plan->getKey(), 'subject_id' => $subject, 'prerequisite_subject_id' => $prerequisite];
    }

    private function plan(string $code): ?StudyPlan
    {
        return array_key_exists($code, $this->plans) ? $this->plans[$code] : $this->plans[$code] = StudyPlan::query()->where('code', $code)->first(['id', 'is_active']);
    }

    /**
     * Las materias del plan sin borrar del catálogo, por clave: las de su retícula (sin lo que se quitó) y las que esperan
     * en «Por validar» (pendientes sin resolver).
     *
     * @return array{grid: array<string, string>, pending: array<string, string>}
     */
    private function places(StudyPlan $plan): array
    {
        return $this->places[$plan->getKey()] ??= [
            'grid' => StudyPlanSubject::query()
                ->join('subjects', 'subjects.id', '=', 'study_plan_subjects.subject_id')
                ->whereNull('subjects.deleted_at')
                ->where('study_plan_subjects.study_plan_id', $plan->getKey())
                ->toBase()
                ->pluck('study_plan_subjects.subject_id', 'subjects.code')
                ->all(),
            'pending' => StudyPlanSubjectPending::query()
                ->join('subjects', 'subjects.id', '=', 'study_plan_subject_pendings.subject_id')
                ->whereNull('subjects.deleted_at')
                ->whereNull('study_plan_subject_pendings.resolved_at')
                ->where('study_plan_subject_pendings.study_plan_id', $plan->getKey())
                ->toBase()
                ->pluck('study_plan_subject_pendings.subject_id', 'subjects.code')
                ->all(),
        ];
    }
}
