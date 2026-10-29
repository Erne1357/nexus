<?php

namespace App\Modules\Imports\Templates;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Models\Campus;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Jobs\RecomputeStudentProgress;
use App\Modules\Students\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Alumnos del SII (tarea 1.4): número de control, nombre, carrera, plan, campus, semestre y estatus. El número
 * de control es la clave natural y es único en todo el Tec, así que el campus viene en el archivo y no de la
 * barra superior. La carrera, el plan (de esa carrera) y el campus se buscan por su clave.
 *
 * Todavía no hay una exportación real: el estatus se reconoce sin importar mayúsculas, acentos ni espacios, y
 * uno que Nexus no conoce no detiene la importación. El alumno queda sin estatus, también si ya tenía uno,
 * para que no siga contando como activo, con advertencia; lo que vino queda en `import_rows` para agregar al
 * enum los que se repitan (confirmado el 29 de octubre de 2026).
 *
 * No ofrece "desactivar faltantes": una exportación suele ser de un campus y el número de control es de todo el
 * Tec, así que desactivaría a los alumnos de los demás campus; las bajas llegan en el estatus. Busca cada
 * carrera, plan y campus una vez por corrida. Al terminar el lote despacha el recálculo del progreso.
 */
class StudentsTemplate extends ImportTemplate
{
    /** @var array<string, Career|null> carrera por clave, en esta corrida */
    private array $careers = [];

    /** @var array<string, StudyPlan|null> plan por clave, en esta corrida */
    private array $plans = [];

    /** @var array<string, string|null> id de campus por clave, en esta corrida */
    private array $campuses = [];

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

    public function deactivatesMissing(): bool
    {
        return false;
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

        foreach (['control_number', 'career', 'study_plan', 'campus'] as $field) {
            $row[$field] = self::asText($row[$field]);
        }

        foreach (['career', 'study_plan', 'campus'] as $field) {
            $row[$field] = is_string($row[$field]) ? Str::upper($row[$field]) : $row[$field];
        }

        $row['semester'] = is_string($row['semester']) && ctype_digit($row['semester']) ? (int) $row['semester'] : $row['semester'];
        $row['status'] = is_string($row['status']) ? $this->status($row['status']) : $row['status'];

        return $row;
    }

    /**
     * Como la base, pero un estatus que Nexus no conoce deja al alumno sin estatus también en un cambio: el
     * cambio no pisa con null lo que el archivo trae vacío, y aquí el archivo sí trajo un estatus.
     */
    public function apply(array $row, ImportContext $context): Model
    {
        $student = parent::apply($row, $context);

        if ($row['status'] !== null && StudentStatus::tryFrom($row['status']) === null && $student->getAttribute('status') !== null) {
            $student->forceFill(['status' => null])->save();
        }

        return $student;
    }

    public function afterApply(ImportContext $context): void
    {
        RecomputeStudentProgress::dispatch();
    }

    protected function check(array $row, ImportContext $context, RowIssues $issues): RowIssues
    {
        $issues = parent::check($row, $context, $issues);
        $career = $this->career($row['career']);

        if ($career === null) {
            $issues = $issues->withError('career', "No hay una carrera con la clave {$row['career']}.");
        }

        $plan = $this->plan($row['study_plan']);

        if ($plan === null) {
            $issues = $issues->withError('study_plan', "No hay un plan con la clave {$row['study_plan']}.");
        } elseif ($career !== null && $plan->career_id !== $career->id) {
            $issues = $issues->withError('study_plan', "El plan {$row['study_plan']} no es de la carrera {$row['career']}.");
        }

        if ($this->campus($row['campus']) === null) {
            $issues = $issues->withError('campus', "No hay un campus con la clave {$row['campus']}.");
        }

        if ($row['status'] !== null && StudentStatus::tryFrom($row['status']) === null) {
            $issues = $issues->withWarning('status', "El estatus «{$row['status']}» no está en Nexus: el alumno queda sin estatus.");
        }

        return $issues;
    }

    protected function attributes(array $row, ImportContext $context): array
    {
        return [
            'name' => $row['name'],
            'career_id' => $this->career($row['career'])?->id,
            'study_plan_id' => $this->plan($row['study_plan'])?->id,
            'campus_id' => $this->campus($row['campus']),
            'current_semester' => $row['semester'],
            'status' => StudentStatus::tryFrom((string) $row['status']),
        ];
    }

    /**
     * El valor de StudentStatus que corresponde al texto del SII, o el texto tal cual si no lo conoce.
     */
    private function status(string $value): string
    {
        return StudentStatus::tryFrom(self::slug($value))?->value ?? $value;
    }

    private function career(string $code): ?Career
    {
        return array_key_exists($code, $this->careers) ? $this->careers[$code] : $this->careers[$code] = Career::query()->where('code', $code)->first(['id']);
    }

    private function plan(string $code): ?StudyPlan
    {
        return array_key_exists($code, $this->plans) ? $this->plans[$code] : $this->plans[$code] = StudyPlan::query()->where('code', $code)->first(['id', 'career_id']);
    }

    private function campus(string $code): ?string
    {
        return array_key_exists($code, $this->campuses) ? $this->campuses[$code] : $this->campuses[$code] = Campus::query()->where('code', $code)->value('id');
    }
}
