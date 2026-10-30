<?php

namespace App\Modules\Imports\Templates;

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Campus;
use App\Modules\Faculty\Enums\TeacherDegree;
use App\Modules\Faculty\Jobs\ProvisionTeacherUsersJob;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Rules\TeacherRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Docentes del SII (tarea 10): número de empleado, nombre, departamento y campus de su adscripción, correo,
 * grado, activo y fecha de ingreso. Un docente con varias adscripciones viene en un renglón por departamento y
 * campus (S5, supuesto por confirmar), como las carreras por campus: la clave del archivo es el docente con su
 * adscripción, para que esos renglones no cuenten como repetidos, y el registro se busca por el número de
 * empleado. La primera adscripción de un docente es la principal; si ya tenía otra principal, la conserva y
 * avisa. El número se normaliza con `TeacherRules`, igual que uno capturado.
 *
 * Como todo lo importado, no reactiva ni restaura (S4, supuesto por confirmar): el docente que el SII no marca
 * activo nace inactivo, uno activo que el archivo marca inactivo sigue activo con advertencia, y uno inactivo se
 * actualiza y sigue inactivo. No ofrece «desactivar faltantes»: es global, la exportación puede ser de un campus
 * y la baja desactiva usuarios. Un grado desconocido queda vacío con advertencia (S6, supuesto por confirmar).
 * Al aplicar el lote despacha el alta de usuarios de los docentes.
 */
class TeachersTemplate extends ImportTemplate
{
    use ParsesSiiDates;

    /** @var array<string, Department|null> departamento por clave, en esta corrida */
    private array $departments = [];

    /** @var array<string, Campus|null> campus por clave, en esta corrida */
    private array $campuses = [];

    /** @var array<string, true> docentes cuya última fila aplicada solo agregó una adscripción */
    private array $adscriptionOnly = [];

    public function key(): string
    {
        return 'teachers';
    }

    public function label(): string
    {
        return 'Docentes';
    }

    public function fields(): array
    {
        return [
            'employee_number' => 'número de empleado',
            'name' => 'nombre',
            'department' => 'departamento',
            'campus' => 'campus',
            'email' => 'correo',
            'degree' => 'grado',
            'active' => 'activo',
            'hired_on' => 'fecha de ingreso',
        ];
    }

    public function naturalKey(): array
    {
        return ['employee_number', 'department', 'campus'];
    }

    public function model(): string
    {
        return Teacher::class;
    }

    public function deactivatesMissing(): bool
    {
        return false;
    }

    /**
     * Los renglones de un docente deben traer el mismo nombre; uno distinto solo avisa y se queda el del primero.
     */
    public function consistentAcrossRows(): ?array
    {
        return ['group' => ['employee_number'], 'errors' => [], 'warnings' => ['name']];
    }

    public function rules(): array
    {
        return [
            'employee_number' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
            'department' => ['required', 'string'],
            'campus' => ['required', 'string'],
            'email' => ['nullable', 'email', 'max:255'],
            'degree' => ['nullable', 'string'],
            'active' => ['nullable', 'boolean'],
            'hired_on' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function normalize(array $row): array
    {
        $row = parent::normalize($row);

        foreach (['employee_number', 'department', 'campus'] as $field) {
            $row[$field] = self::asText($row[$field]);
        }

        if (is_string($row['employee_number'])) {
            try {
                $row['employee_number'] = app(TeacherRules::class)->normalizeEmployeeNumber($row['employee_number']);
            } catch (ValidationException) {
                // Texto que no es UTF-8 válido: validate() lo reporta.
            }
        }

        foreach (['department', 'campus'] as $field) {
            $row[$field] = is_string($row[$field]) ? Str::upper($row[$field]) : $row[$field];
        }

        $row['degree'] = is_string($row['degree']) ? (TeacherDegree::tryFrom(self::slug($row['degree']))?->value ?? $row['degree']) : $row['degree'];
        $row['active'] = $this->active($row['active']);
        $row['hired_on'] = self::siiDate($row['hired_on']);

        return $row;
    }

    /**
     * Como la base, y además la adscripción del renglón: la principal si el docente no tiene otra. Un grado que
     * Nexus no conoce deja al docente sin grado también en un cambio: el cambio no pisa con null lo que el
     * archivo trae vacío, y aquí el archivo sí trajo un grado. Un renglón que solo agrega la adscripción cuenta
     * como cambio del docente.
     */
    public function apply(array $row, ImportContext $context): Model
    {
        $teacher = parent::apply($row, $context);

        if ($row['degree'] !== null && TeacherDegree::tryFrom($row['degree']) === null && $teacher->getAttribute('degree') !== null) {
            $teacher->forceFill(['degree' => null])->save();
        }

        $adscription = [
            'teacher_id' => $teacher->getKey(),
            'department_id' => $this->department($row['department'])?->getKey(),
            'campus_id' => $this->campus($row['campus'])?->getKey(),
        ];

        unset($this->adscriptionOnly[$teacher->getKey()]);

        if (! TeacherDepartment::withTrashed()->where($adscription)->exists()) {
            $hasPrimary = TeacherDepartment::query()->where('teacher_id', $teacher->getKey())->where('is_primary', true)->exists();
            TeacherDepartment::query()->create([...$adscription, 'is_primary' => ! $hasPrimary]);

            if (! $teacher->wasRecentlyCreated && ! $teacher->wasChanged()) {
                $this->adscriptionOnly[$teacher->getKey()] = true;
            }
        }

        return $teacher;
    }

    public function wroteAlongside(Model $model): bool
    {
        return isset($this->adscriptionOnly[$model->getKey()]);
    }

    public function afterApply(ImportContext $context): void
    {
        ProvisionTeacherUsersJob::dispatch($context->user);
    }

    protected function check(array $row, ImportContext $context, RowIssues $issues): RowIssues
    {
        $issues = parent::check($row, $context, $issues);

        if ($row['degree'] !== null && TeacherDegree::tryFrom($row['degree']) === null) {
            $issues = $issues->withWarning('degree', "El grado «{$row['degree']}» no está en Nexus: el docente queda sin grado.");
        }

        $department = $this->department($row['department']);

        if ($department === null) {
            $issues = $issues->withError('department', "No hay un departamento con la clave {$row['department']}.");
        } elseif (! $department->is_active) {
            $issues = $issues->withWarning('department', "El departamento {$row['department']} está inactivo.");
        }

        $campus = $this->campus($row['campus']);

        if ($campus === null) {
            $issues = $issues->withError('campus', "No hay un campus con la clave {$row['campus']}.");
        } elseif (! $campus->is_active) {
            $issues = $issues->withWarning('campus', "El campus {$row['campus']} está inactivo.");
        }

        $teacher = Teacher::withTrashed()->where('employee_number', $row['employee_number'])->first(['id', 'is_active', 'deleted_at']);

        if ($teacher === null || $teacher->trashed()) {
            return $issues;
        }

        if ($row['active'] === false && $teacher->is_active) {
            $issues = $issues->withWarning('active', 'El archivo lo marca inactivo, pero el docente sigue activo: una importación no lo da de baja.');
        }

        if ($department === null || $campus === null) {
            return $issues;
        }

        $adscription = TeacherDepartment::withTrashed()
            ->where(['teacher_id' => $teacher->getKey(), 'department_id' => $department->getKey(), 'campus_id' => $campus->getKey()])
            ->first(['id', 'deleted_at']);

        if ($adscription?->trashed()) {
            return $issues->withError('department', "La adscripción del docente a {$row['department']} en {$row['campus']} está borrada; una importación no la restaura.");
        }

        if ($adscription === null && TeacherDepartment::query()->where('teacher_id', $teacher->getKey())->where('is_primary', true)->exists()) {
            $issues = $issues->withWarning('department', 'El docente ya tiene otra adscripción principal: esta se agrega como no principal.');
        }

        return $issues;
    }

    protected function recordKey(array $row, ImportContext $context): ?array
    {
        return ['employee_number' => $row['employee_number']];
    }

    protected function attributes(array $row, ImportContext $context): array
    {
        return [
            'name' => $row['name'],
            'email' => $row['email'],
            'degree' => TeacherDegree::tryFrom((string) $row['degree']),
            'hired_on' => $row['hired_on'],
            'is_active' => $row['active'] ?? true,
        ];
    }

    /**
     * El «activo» del SII como booleano; vacío es null (nace activo) y lo que no entiende se queda como texto,
     * para que `rules()` lo rechace.
     */
    private function active(mixed $value): mixed
    {
        if (! is_string($value) && ! is_int($value)) {
            return $value;
        }

        return match (self::slug((string) $value)) {
            'si', 's', 'activo', '1', 'true', 'verdadero' => true,
            'no', 'n', 'inactivo', '0', 'false', 'falso', 'baja' => false,
            default => $value,
        };
    }

    private function department(string $code): ?Department
    {
        return array_key_exists($code, $this->departments) ? $this->departments[$code] : $this->departments[$code] = Department::query()->where('code', $code)->first(['id', 'is_active']);
    }

    private function campus(string $code): ?Campus
    {
        return array_key_exists($code, $this->campuses) ? $this->campuses[$code] : $this->campuses[$code] = Campus::query()->where('code', $code)->first(['id', 'is_active']);
    }
}
