<?php

namespace App\Modules\Faculty\Queries;

use App\Modules\Core\Queries\UnitAdscriptions;
use App\Modules\Faculty\Models\TeacherDepartment;

/**
 * Las adscripciones de los docentes para el Organigrama de Core: el id de un departamento es el de su unidad. Cuentan
 * las adscripciones sin borrar de los docentes activos, sin borrar y con usuario. Una sola consulta.
 */
final class TeacherUnitAdscriptions implements UnitAdscriptions
{
    public function userIdsByUnit(): array
    {
        $byUnit = [];

        $rows = TeacherDepartment::query()
            ->join('teachers', 'teachers.id', '=', 'teacher_departments.teacher_id')
            ->whereNull('teachers.deleted_at')
            ->where('teachers.is_active', true)
            ->whereNotNull('teachers.user_id')
            ->distinct()
            ->orderBy('teacher_departments.department_id')
            ->orderBy('teachers.user_id')
            ->get(['teacher_departments.department_id', 'teachers.user_id']);

        foreach ($rows as $row) {
            $byUnit[$row->department_id][] = $row->user_id;
        }

        return $byUnit;
    }
}
