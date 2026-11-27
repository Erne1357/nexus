<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\Academic\Models\Subject;
use Illuminate\Support\Str;

/**
 * PL-02 (tarea 1.73) en la semana publicada de un docente (pantalla y PDF): las horas de clase de la semana siguen
 * siendo las de su horario; si alguna de sus materias no cuenta en la carga del docente (las tutorías), también las que
 * sí le cuentan. La versión publicada no guarda la marca, así que se lee de la materia hoy (supuesto por confirmar). Una
 * consulta.
 */
final class PublishedTeacherHoursQuery
{
    /**
     * Las horas de clase a la semana de los grupos cuya materia cuenta en la carga del docente, o null si todas cuentan
     * (entonces no hay nada que aclarar).
     *
     * @param  list<PublishedGroup>  $groups
     */
    public function counted(array $groups): int|float|null
    {
        // Solo ids de materia válidos: una versión publicada vieja o de prueba puede traer otra cosa.
        $subjectIds = array_values(array_filter(array_unique(array_map(fn (PublishedGroup $group): string => (string) ($group->subject['id'] ?? ''), $groups)), Str::isUuid(...)));

        if ($subjectIds === []) {
            return null;
        }

        $notCounting = Subject::query()->withTrashed()->whereIn('id', $subjectIds)->where('counts_teacher_hours', false)->pluck('id')->flip();

        if ($notCounting->isEmpty()) {
            return null;
        }

        return PublishedWeek::weeklyHours(array_values(array_filter($groups, fn (PublishedGroup $group): bool => ! $notCounting->has((string) ($group->subject['id'] ?? '')))));
    }
}
