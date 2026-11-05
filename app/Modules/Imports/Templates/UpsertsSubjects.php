<?php

namespace App\Modules\Imports\Templates;

use App\Modules\Academic\Enums\SubjectKind;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Lo que `subjects` y `pending_placements` hacen igual con la materia global y su especialidad: la misma alta o
 * cambio por clave (sin tocar `is_active`; `kind` solo si el archivo lo trae), la misma especialidad creada con su
 * clave y su nombre, y las mismas búsquedas de plan, departamento y materias del mismo nombre, una vez por corrida.
 * Quien la usa declara los campos `plan`, `code`, `name`, `theory_hours`, `practice_hours`, `credits`,
 * `department`, `specialty`, `specialty_name` y `kind` en su fila normalizada.
 */
trait UpsertsSubjects
{
    /** @var array<string, StudyPlan|null> plan por clave, en esta corrida */
    private array $plans = [];

    /** @var array<string, Department|null> departamento por clave, en esta corrida */
    private array $departments = [];

    /** @var array<string, list<string>>|null claves de materia por nombre en minúsculas, en esta corrida */
    private ?array $codesByName = null;

    /**
     * Los límites de los campos que `subjects` y `pending_placements` comparten: solo los del modelo de datos
     * (horas y créditos no negativos; el tope es el de la columna smallint, no una regla).
     *
     * @return array<string, list<mixed>>
     */
    private function subjectRules(): array
    {
        return [
            'plan' => ['required', 'string'],
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
            'theory_hours' => ['required', 'integer', 'min:0', 'max:32767'],
            'practice_hours' => ['required', 'integer', 'min:0', 'max:32767'],
            'credits' => ['required', 'integer', 'min:0', 'max:32767'],
            'department' => ['required', 'string'],
            'specialty' => ['nullable', 'string', 'max:30'],
            'specialty_name' => ['nullable', 'string', 'max:255'],
            'kind' => ['nullable', Rule::enum(SubjectKind::class)],
        ];
    }

    /**
     * Normaliza los campos compartidos: claves en mayúsculas, enteros como enteros y el tipo por su valor del enum.
     * `$texts` y `$integers` suman los campos propios de la plantilla que se tratan igual.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $texts
     * @param  list<string>  $integers
     * @return array<string, mixed>
     */
    private function normalizeSubjectFields(array $row, array $texts = [], array $integers = []): array
    {
        foreach (['plan', 'code', 'department', 'specialty', ...$texts] as $field) {
            $row[$field] = self::asText($row[$field]);
            $row[$field] = is_string($row[$field]) ? Str::upper($row[$field]) : $row[$field];
        }

        foreach (['theory_hours', 'practice_hours', 'credits', ...$integers] as $field) {
            $value = self::asText($row[$field]);
            $row[$field] = is_string($value) && ctype_digit($value) ? (int) $value : $row[$field];
        }

        $kind = self::asText($row['kind']);
        $row['kind'] = is_string($kind) ? (SubjectKind::tryFrom(self::slug($kind))?->value ?? $kind) : $kind;

        return $row;
    }

    /**
     * Da de alta o actualiza la materia por su clave.
     *
     * @param  array<string, mixed>  $row
     */
    private function upsertSubject(array $row): Subject
    {
        $subject = Subject::query()->where('code', $row['code'])->first() ?? new Subject(['code' => $row['code']]);
        $subject->fill([
            'name' => $row['name'],
            'department_id' => $this->department($row['department'])?->getKey(),
            'theory_hours' => $row['theory_hours'],
            'practice_hours' => $row['practice_hours'],
            'credits' => $row['credits'],
            ...($row['kind'] === null ? [] : ['kind' => $row['kind']]),
        ])->save();

        return $subject;
    }

    /**
     * La especialidad de la fila en el plan: la crea con su nombre (o su clave) si falta y, si ya estaba, le pone el
     * nombre del archivo cuando viene.
     *
     * @param  array<string, mixed>  $row
     */
    private function upsertSpecialty(array $row, StudyPlan $plan): ?Specialty
    {
        $specialty = $row['specialty'] === null ? null : Specialty::query()->firstOrCreate(
            ['study_plan_id' => $plan->getKey(), 'code' => $row['specialty']],
            ['name' => $row['specialty_name'] ?? $row['specialty'], 'is_active' => (bool) $plan->is_active],
        );

        if ($specialty !== null && ! $specialty->wasRecentlyCreated && $row['specialty_name'] !== null) {
            $specialty->fill(['name' => $row['specialty_name']])->save();
        }

        return $specialty;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function checkPlanAndDepartment(array $row, RowIssues $issues): RowIssues
    {
        $plan = $this->plan($row['plan']);

        if ($plan === null) {
            $issues = $issues->withError('plan', "No hay un plan con la clave {$row['plan']}.");
        } elseif (! $plan->is_active) {
            $issues = $issues->withWarning('plan', "El plan {$row['plan']} está inactivo.");
        }

        $department = $this->department($row['department']);

        if ($department === null) {
            return $issues->withError('department', "No hay un departamento con la clave {$row['department']}.");
        }

        return $department->is_active ? $issues : $issues->withWarning('department', "El departamento {$row['department']} está inactivo.");
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function checkSubjectTwin(array $row, RowIssues $issues): RowIssues
    {
        $twin = collect($this->codesByName()[mb_strtolower($row['name'])] ?? [])->first(fn (string $code): bool => $code !== $row['code']);

        return $twin === null ? $issues : $issues->withWarning('name', "La materia {$twin} tiene el mismo nombre con otra clave; no se unifican.");
    }

    /**
     * @return array<string, list<string>>
     */
    private function codesByName(): array
    {
        return $this->codesByName ??= Subject::query()->get(['code', 'name'])
            ->groupBy(fn (Subject $subject): string => mb_strtolower($subject->name))
            ->map(fn ($subjects): array => $subjects->pluck('code')->all())
            ->all();
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
