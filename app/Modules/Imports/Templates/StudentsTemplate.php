<?php

namespace App\Modules\Imports\Templates;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Models\Campus;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Jobs\RecomputeStudentProgress;
use App\Modules\Students\Models\Student;
use Illuminate\Support\Str;

/**
 * Alumnos del SII (tarea 1.4): número de control, nombre, carrera, plan, campus, semestre y estatus. El número
 * de control es la clave natural y es único en todo el Tec, así que el campus viene en el archivo y no de la
 * barra superior. La carrera, el plan (de esa carrera) y el campus se buscan por su clave.
 *
 * Todavía no hay una exportación real: el estatus se reconoce sin importar mayúsculas, acentos ni espacios, y
 * uno que Nexus no conoce no detiene la importación. El alumno entra sin estatus (o conserva el que tenía) y
 * con advertencia, y lo que vino queda en `import_rows` para agregar al enum los que se repitan (confirmado el
 * 29 de octubre de 2026).
 * Al terminar el lote despacha el recálculo del progreso.
 */
class StudentsTemplate extends ImportTemplate
{
    public function key(): string
    {
        return 'students';
    }

    public function label(): string
    {
        return 'Alumnos';
    }

    public function fields(): array
    {
        return [
            'control_number' => 'número de control',
            'name' => 'nombre',
            'career' => 'carrera',
            'study_plan' => 'plan',
            'campus' => 'campus',
            'semester' => 'semestre',
            'status' => 'estatus',
        ];
    }

    public function naturalKey(): array
    {
        return ['control_number'];
    }

    public function model(): string
    {
        return Student::class;
    }

    public function rules(): array
    {
        return [
            'control_number' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
            'career' => ['required', 'string'],
            'study_plan' => ['required', 'string'],
            'campus' => ['required', 'string'],
            'semester' => ['nullable', 'integer', 'min:1', 'max:20'],
            'status' => ['nullable', 'string'],
        ];
    }

    public function normalize(array $row): array
    {
        $row = parent::normalize($row);

        $row['control_number'] = $row['control_number'] === null ? null : (string) $row['control_number'];

        foreach (['career', 'study_plan', 'campus'] as $field) {
            $row[$field] = is_string($row[$field]) ? Str::upper($row[$field]) : $row[$field];
        }

        $row['semester'] = is_string($row['semester']) && ctype_digit($row['semester']) ? (int) $row['semester'] : $row['semester'];
        $row['status'] = is_string($row['status']) ? $this->status($row['status']) : $row['status'];

        return $row;
    }

    public function afterApply(ImportContext $context): void
    {
        RecomputeStudentProgress::dispatch();
    }

    protected function check(array $row, ImportContext $context, RowIssues $issues): RowIssues
    {
        $issues = parent::check($row, $context, $issues);
        $career = Career::query()->where('code', $row['career'])->first();

        if ($career === null) {
            $issues = $issues->withError('career', "No hay una carrera con la clave {$row['career']}.");
        }

        $plan = StudyPlan::query()->where('code', $row['study_plan'])->first();

        if ($plan === null) {
            $issues = $issues->withError('study_plan', "No hay un plan con la clave {$row['study_plan']}.");
        } elseif ($career !== null && $plan->career_id !== $career->id) {
            $issues = $issues->withError('study_plan', "El plan {$row['study_plan']} no es de la carrera {$row['career']}.");
        }

        if (! Campus::query()->where('code', $row['campus'])->exists()) {
            $issues = $issues->withError('campus', "No hay un campus con la clave {$row['campus']}.");
        }

        if ($row['status'] !== null && StudentStatus::tryFrom($row['status']) === null) {
            $issues = $issues->withWarning('status', "El estatus «{$row['status']}» no está en Nexus: el alumno se importa sin estatus.");
        }

        return $issues;
    }

    protected function attributes(array $row, ImportContext $context): array
    {
        return [
            'name' => $row['name'],
            'career_id' => Career::query()->where('code', $row['career'])->value('id'),
            'study_plan_id' => StudyPlan::query()->where('code', $row['study_plan'])->value('id'),
            'campus_id' => Campus::query()->where('code', $row['campus'])->value('id'),
            'current_semester' => $row['semester'],
            'status' => StudentStatus::tryFrom((string) $row['status']),
        ];
    }

    /**
     * El valor de StudentStatus que corresponde al texto del SII, o el texto tal cual si no lo conoce.
     */
    private function status(string $value): string
    {
        $slug = Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z]+/', '_')->trim('_')->value();

        return StudentStatus::tryFrom($slug)?->value ?? $value;
    }
}
