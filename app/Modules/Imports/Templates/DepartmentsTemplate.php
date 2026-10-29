<?php

namespace App\Modules\Imports\Templates;

use App\Modules\Academic\Models\Department;
use Illuminate\Support\Str;

/**
 * Departamentos del organigrama (tarea 1.9): clave y nombre. Se importan primero, porque las carreras y las
 * materias del SII traen la clave de su departamento (confirmado el 29 de octubre de 2026). La clave es la
 * natural y se guarda en mayúsculas.
 *
 * No ofrece "desactivar faltantes": la exportación puede no traer el organigrama completo, y desactivar un
 * departamento es una decisión que se toma a mano.
 */
class DepartmentsTemplate extends ImportTemplate
{
    public function key(): string
    {
        return 'departments';
    }

    public function label(): string
    {
        return 'Departamentos';
    }

    public function fields(): array
    {
        return [
            'code' => 'clave',
            'name' => 'nombre',
        ];
    }

    public function naturalKey(): array
    {
        return ['code'];
    }

    public function model(): string
    {
        return Department::class;
    }

    public function deactivatesMissing(): bool
    {
        return false;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    public function normalize(array $row): array
    {
        $row = parent::normalize($row);
        $row['code'] = self::asText($row['code']);
        $row['code'] = is_string($row['code']) ? Str::upper($row['code']) : $row['code'];

        return $row;
    }
}
