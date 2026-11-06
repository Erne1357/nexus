<?php

namespace App\Modules\Imports\Templates;

use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\SubjectAlias;
use App\Modules\Students\Jobs\RecomputeStudentProgress;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * «Materias por plan» del SII (tarea 1.9): cada renglón es una materia en la retícula de un plan, con su clave,
 * nombre, semestre, horas, créditos, departamento que la imparte y, si es de especialidad, la clave de su
 * especialidad. Da de alta o actualiza la materia, que es global y se busca por su clave, y su lugar en la
 * retícula (`study_plan_subjects`): semestre y especialidad. La especialidad que el plan no tiene se crea con su
 * clave y con su nombre si viene; si no, se llama como su clave hasta que se capture, y la de un plan inactivo nace
 * inactiva. Una materia que tenía especialidad y el SII ya no se la trae la conserva, con advertencia (confirmado el
 * 29 de octubre de 2026). La clave del archivo es el plan con la materia.
 *
 * Una materia viene en un renglón por plan: si dos renglones no coinciden en créditos, horas o departamento, el
 * posterior es error; si solo cambia el nombre, avisa y se queda el del primero (consistentAcrossRows(),
 * confirmado el 29 de octubre de 2026). El SII puede dar claves distintas a la «misma» materia en planes
 * distintos: si otra clave ya tiene el mismo nombre, en la base o en el archivo (twinsAcrossRows()), avisa, pero
 * no las unifica.
 *
 * Como todo lo importado, no reactiva ni restaura: una materia inactiva se actualiza y sigue inactiva, y una
 * materia, una especialidad o un lugar en la retícula borrados son error. No ofrece "desactivar faltantes": quitar
 * una materia de la retícula cambia el avance de los alumnos y se hace a mano. Al terminar el lote recalcula el
 * avance, porque cambió la retícula. Busca cada plan y departamento una vez por corrida.
 *
 * `sii_code` es la clave con que el SII conoce la materia en ese plan: se guarda como alias de la materia
 * (`subject_aliases`), y un alias que ya apunta a otra materia es error; a la misma, no hace nada. `kind` es el tipo
 * (`SubjectKind`): se escribe al dar de alta y, en un cambio, solo si el archivo lo trae. Ambos, confirmado el 5 de
 * noviembre de 2026 (D15); que el alias y el tipo se comporten así es un supuesto por confirmar.
 *
 * `position` es el renglón de la materia dentro de su semestre (D21, confirmado el 5 de noviembre de 2026; de 1 a 99,
 * opcional): se escribe al dar de alta el lugar y, en un cambio, solo si el archivo lo trae (supuesto por confirmar).
 */
class SubjectsTemplate extends ImportTemplate
{
    use UpsertsSubjects;

    /** @var array<string, true> lugares en la retícula cuya última fila aplicada solo cambió la materia */
    private array $subjectOnly = [];

    public function key(): string
    {
        return 'subjects';
    }

    public function label(): string
    {
        return 'Materias por plan';
    }

    public function fields(): array
    {
        return [
            'plan' => 'clave de plan',
            'code' => 'clave de materia',
            'name' => 'nombre',
            'semester' => 'semestre',
            'theory_hours' => 'horas teoría',
            'practice_hours' => 'horas práctica',
            'credits' => 'créditos',
            'department' => 'departamento',
            'specialty' => 'especialidad',
            'specialty_name' => 'nombre de especialidad',
            'sii_code' => 'clave del SII',
            'kind' => 'tipo',
            'position' => 'renglón',
        ];
    }

    public function naturalKey(): array
    {
        return ['plan', 'code'];
    }

    public function model(): string
    {
        return StudyPlanSubject::class;
    }

    public function deactivatesMissing(): bool
    {
        return false;
    }

    public function consistentAcrossRows(): ?array
    {
        return ['group' => ['code'], 'errors' => ['credits', 'theory_hours', 'practice_hours', 'department'], 'warnings' => ['name']];
    }

    public function exclusiveAcrossRows(): ?array
    {
        return ['value' => 'sii_code', 'owner' => 'code'];
    }

    public function twinsAcrossRows(): ?array
    {
        return ['name' => 'name', 'key' => 'code'];
    }

    /**
     * Solo los límites del modelo de datos: semestre desde 1 y los de los campos que comparte con
     * `pending_placements`.
     */
    public function rules(): array
    {
        return [
            ...$this->subjectRules(),
            'semester' => ['required', 'integer', 'min:1', 'max:32767'],
            'sii_code' => ['nullable', 'string', 'max:20'],
            'position' => ['nullable', 'integer', 'min:1', 'max:99'],
        ];
    }

    public function normalize(array $row): array
    {
        return $this->normalizeSubjectFields(parent::normalize($row), ['sii_code'], ['semester', 'position']);
    }

    /**
     * Escribe la materia (alta o cambio por clave, sin tocar `is_active`), la especialidad si falta y el lugar de
     * la materia en la retícula. Devuelve ese lugar; si la fila solo cambió la materia o creó la especialidad,
     * wroteAlongside() lo dice para que cuente como cambio.
     */
    public function apply(array $row, ImportContext $context): Model
    {
        $plan = $this->plan($row['plan']);
        $subject = $this->upsertSubject($row);

        $alias = $row['sii_code'] === null ? null : SubjectAlias::query()->firstOrCreate(
            ['code' => $row['sii_code']],
            ['subject_id' => $subject->getKey(), 'study_plan_id' => $plan->getKey()],
        );

        if ($alias !== null && $alias->subject_id !== $subject->getKey()) {
            throw new LogicException("La clave del SII {$row['sii_code']} ya es de otra materia: validate() lo reporta como error.");
        }

        $specialty = $this->upsertSpecialty($row, $plan);

        $placement = StudyPlanSubject::query()->where(['study_plan_id' => $plan->getKey(), 'subject_id' => $subject->getKey()])->first()
            ?? new StudyPlanSubject(['study_plan_id' => $plan->getKey(), 'subject_id' => $subject->getKey()]);
        $placement->fill([
            'semester' => $row['semester'],
            ...($specialty === null ? [] : ['specialty_id' => $specialty->getKey()]),
            ...($row['position'] === null ? [] : ['position' => $row['position']]),
        ])->save();

        unset($this->subjectOnly[$placement->getKey()]);

        if (! $placement->wasRecentlyCreated && ! $placement->wasChanged()
            && ($subject->wasRecentlyCreated || $subject->wasChanged() || $specialty?->wasRecentlyCreated || $specialty?->wasChanged() || $alias?->wasRecentlyCreated)) {
            $this->subjectOnly[$placement->getKey()] = true;
        }

        return $placement;
    }

    public function wroteAlongside(Model $model): bool
    {
        return isset($this->subjectOnly[$model->getKey()]);
    }

    public function afterApply(ImportContext $context): void
    {
        RecomputeStudentProgress::dispatch();
    }

    protected function check(array $row, ImportContext $context, RowIssues $issues): RowIssues
    {
        $plan = $this->plan($row['plan']);
        $issues = $this->checkPlanAndDepartment($row, $issues);

        $subject = Subject::withTrashed()->where('code', $row['code'])->first(['id', 'is_active', 'deleted_at']);

        if ($subject?->trashed()) {
            return $issues->withError('code', "Hay una materia borrada con la clave {$row['code']}; una importación no la restaura.");
        }

        if ($subject !== null && ! $subject->is_active) {
            $issues = $issues->withWarning('code', "La materia {$row['code']} está inactiva: se actualizan sus datos, pero sigue inactiva.");
        }

        $alias = $row['sii_code'] === null ? null : SubjectAlias::query()->where('code', $row['sii_code'])->first(['subject_id']);

        if ($alias !== null && $alias->subject_id !== $subject?->getKey()) {
            $issues = $issues->withError('sii_code', "La clave del SII {$row['sii_code']} ya es de otra materia.");
        }

        $issues = $this->checkSubjectTwin($row, $issues);

        if ($plan === null) {
            return $issues;
        }

        if ($subject !== null && StudyPlanSubject::onlyTrashed()->where(['study_plan_id' => $plan->getKey(), 'subject_id' => $subject->getKey()])->exists()) {
            $issues = $issues->withError('code', "La materia {$row['code']} se quitó de la retícula del plan {$row['plan']}; una importación no la vuelve a poner.");
        }

        $specialty = $row['specialty'] === null ? null : Specialty::withTrashed()->where(['study_plan_id' => $plan->getKey(), 'code' => $row['specialty']])->first(['id', 'is_active', 'deleted_at']);

        if ($specialty?->trashed()) {
            $issues = $issues->withError('specialty', "La especialidad {$row['specialty']} del plan {$row['plan']} está borrada; una importación no la restaura.");
        } elseif ($specialty !== null && ! $specialty->is_active) {
            $issues = $issues->withWarning('specialty', "La especialidad {$row['specialty']} del plan {$row['plan']} está inactiva.");
        }

        $current = $row['specialty'] !== null || $subject === null ? null : StudyPlanSubject::query()
            ->where(['study_plan_id' => $plan->getKey(), 'subject_id' => $subject->getKey()])
            ->whereNotNull('specialty_id')
            ->value('specialty_id');

        if ($current !== null) {
            $code = Specialty::withTrashed()->whereKey($current)->value('code');
            $issues = $issues->withWarning('specialty', "El SII ya no trae especialidad para {$row['code']} en {$row['plan']}: se queda en {$code}; quitarla es una edición a mano en la retícula.");
        }

        return $issues;
    }
}
