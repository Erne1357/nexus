<?php

namespace App\Modules\Imports\Templates;

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\Students\Jobs\RecomputeStudentProgress;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * «Materias por plan» del SII (tarea 1.9): cada renglón es una materia en la retícula de un plan, con su clave,
 * nombre, semestre, horas, créditos, departamento que la imparte y, si es de especialidad, la clave de su
 * especialidad. Da de alta o actualiza la materia, que es global y se busca por su clave, y su lugar en la
 * retícula (`study_plan_subjects`): semestre y especialidad. La especialidad que el plan no tiene se crea con su
 * clave y con su nombre si viene; si no, se llama como su clave hasta que se capture (confirmado el 29 de octubre
 * de 2026). La clave del archivo es el plan con la materia.
 *
 * Una materia viene en un renglón por plan: si dos renglones no coinciden en créditos, horas o departamento, el
 * posterior es error; si solo cambia el nombre, avisa y se queda el del primero (consistentAcrossRows(),
 * confirmado el 29 de octubre de 2026). El SII puede dar claves distintas a la «misma» materia en planes
 * distintos: si otra clave ya tiene el mismo nombre, avisa, pero no las unifica.
 *
 * Como todo lo importado, no reactiva ni restaura: una materia inactiva se actualiza y sigue inactiva, y una
 * materia, una especialidad o un lugar en la retícula borrados son error. No ofrece "desactivar faltantes": quitar
 * una materia de la retícula cambia el avance de los alumnos y se hace a mano. Al terminar el lote recalcula el
 * avance, porque cambió la retícula. Busca cada plan y departamento una vez por corrida.
 */
class SubjectsTemplate extends ImportTemplate
{
    /** @var array<string, StudyPlan|null> plan por clave, en esta corrida */
    private array $plans = [];

    /** @var array<string, Department|null> departamento por clave, en esta corrida */
    private array $departments = [];

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

    public function rules(): array
    {
        return [
            'plan' => ['required', 'string'],
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
            'semester' => ['required', 'integer', 'min:1', 'max:20'],
            'theory_hours' => ['required', 'integer', 'min:0', 'max:40'],
            'practice_hours' => ['required', 'integer', 'min:0', 'max:40'],
            'credits' => ['required', 'integer', 'min:0', 'max:40'],
            'department' => ['required', 'string'],
            'specialty' => ['nullable', 'string', 'max:30'],
            'specialty_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function normalize(array $row): array
    {
        $row = parent::normalize($row);

        foreach (['plan', 'code', 'department', 'specialty'] as $field) {
            $row[$field] = self::asText($row[$field]);
            $row[$field] = is_string($row[$field]) ? Str::upper($row[$field]) : $row[$field];
        }

        foreach (['semester', 'theory_hours', 'practice_hours', 'credits'] as $field) {
            $value = self::asText($row[$field]);
            $row[$field] = is_string($value) && ctype_digit($value) ? (int) $value : $row[$field];
        }

        return $row;
    }

    /**
     * Escribe la materia (alta o cambio por clave, sin tocar `is_active`), la especialidad si falta y el lugar de
     * la materia en la retícula. Devuelve ese lugar; si la fila solo cambió la materia o creó la especialidad,
     * wroteAlongside() lo dice para que cuente como cambio.
     */
    public function apply(array $row, ImportContext $context): Model
    {
        $plan = $this->plan($row['plan']);
        $subject = Subject::query()->where('code', $row['code'])->first() ?? new Subject(['code' => $row['code']]);
        $subject->fill([
            'name' => $row['name'],
            'department_id' => $this->department($row['department'])?->getKey(),
            'theory_hours' => $row['theory_hours'],
            'practice_hours' => $row['practice_hours'],
            'credits' => $row['credits'],
        ])->save();

        $specialty = $row['specialty'] === null ? null : Specialty::query()->firstOrCreate(
            ['study_plan_id' => $plan->getKey(), 'code' => $row['specialty']],
            ['name' => $row['specialty_name'] ?? $row['specialty'], 'is_active' => true],
        );

        $placement = StudyPlanSubject::query()->where(['study_plan_id' => $plan->getKey(), 'subject_id' => $subject->getKey()])->first()
            ?? new StudyPlanSubject(['study_plan_id' => $plan->getKey(), 'subject_id' => $subject->getKey()]);
        $placement->fill(['semester' => $row['semester'], ...($specialty === null ? [] : ['specialty_id' => $specialty->getKey()])])->save();

        unset($this->subjectOnly[$placement->getKey()]);

        if (! $placement->wasRecentlyCreated && ! $placement->wasChanged()
            && ($subject->wasRecentlyCreated || $subject->wasChanged() || $specialty?->wasRecentlyCreated)) {
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

        if ($plan === null) {
            $issues = $issues->withError('plan', "No hay un plan con la clave {$row['plan']}.");
        } elseif (! $plan->is_active) {
            $issues = $issues->withWarning('plan', "El plan {$row['plan']} está inactivo.");
        }

        $department = $this->department($row['department']);

        if ($department === null) {
            $issues = $issues->withError('department', "No hay un departamento con la clave {$row['department']}.");
        } elseif (! $department->is_active) {
            $issues = $issues->withWarning('department', "El departamento {$row['department']} está inactivo.");
        }

        $subject = Subject::withTrashed()->where('code', $row['code'])->first(['id', 'is_active', 'deleted_at']);

        if ($subject?->trashed()) {
            return $issues->withError('code', "Hay una materia borrada con la clave {$row['code']}; una importación no la restaura.");
        }

        if ($subject !== null && ! $subject->is_active) {
            $issues = $issues->withWarning('code', "La materia {$row['code']} está inactiva: se actualizan sus datos, pero sigue inactiva.");
        }

        $twin = Subject::query()->where('code', '<>', $row['code'])->whereRaw('lower(name) = lower(?)', [$row['name']])->value('code');

        if ($twin !== null) {
            $issues = $issues->withWarning('name', "La materia {$twin} tiene el mismo nombre con otra clave; no se unifican.");
        }

        if ($plan === null) {
            return $issues;
        }

        if ($subject !== null && StudyPlanSubject::onlyTrashed()->where(['study_plan_id' => $plan->getKey(), 'subject_id' => $subject->getKey()])->exists()) {
            $issues = $issues->withError('code', "La materia {$row['code']} se quitó de la retícula del plan {$row['plan']}; una importación no la vuelve a poner.");
        }

        if ($row['specialty'] !== null && Specialty::onlyTrashed()->where(['study_plan_id' => $plan->getKey(), 'code' => $row['specialty']])->exists()) {
            $issues = $issues->withError('specialty', "La especialidad {$row['specialty']} del plan {$row['plan']} está borrada; una importación no la restaura.");
        }

        return $issues;
    }

    private function plan(string $code): ?StudyPlan
    {
        return array_key_exists($code, $this->plans) ? $this->plans[$code] : $this->plans[$code] = StudyPlan::query()->where('code', $code)->first(['id', 'is_active']);
    }

    private function department(string $code): ?Department
    {
        return array_key_exists($code, $this->departments) ? $this->departments[$code] : $this->departments[$code] = Department::query()->where('code', $code)->first(['id', 'is_active']);
    }
}
