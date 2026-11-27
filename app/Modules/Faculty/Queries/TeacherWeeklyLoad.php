<?php

namespace App\Modules\Faculty\Queries;

use App\Modules\Faculty\Models\Teacher;

/**
 * La carga semanal de un docente para la pestaña «Carga» de su ficha (D-03): el periodo en curso (el que contiene hoy)
 * y el siguiente periodo con grupos de Nexus, con la semana por día, el salón y las horas contra la base. Faculty no
 * conoce los grupos ni el horario, que están a su derecha en el mapa de módulos: liga NoTeacherWeeklyLoad y Staffing
 * lo reemplaza en su register().
 *
 * Cada semana de Nexus trae además `departments` (tarea 1.59): los departamentos de adscripción del docente (`home`)
 * y los demás donde tiene grupos, cada uno con `name`, `head` (jefe vigente o nulo), `tone`, `hours` y `groups`;
 * vacío si la semana viene del SII, que no conoce el departamento del grupo. Cada bloque de `days` lleva `tone`.
 *
 * `current` y `next` son nulos si no hay periodo o grupos; `upcoming` es el nombre del periodo que sigue al en curso
 * aunque no tenga grupos todavía (para el estado vacío); `base_hours` son las horas base de hoy.
 *
 * @phpstan-type Week array{term: string, code: string, source: 'sii'|'nexus', groups: list<array<string, mixed>>, days: array<int, array{name: string, short: string, hours: int, blocks: list<array<string, mixed>>}>, hours: int, base: int, tolerance: int}
 */
interface TeacherWeeklyLoad
{
    /**
     * @return array{current: ?array<string, mixed>, next: ?array<string, mixed>, upcoming: ?string, base_hours: int}
     */
    public function forTeacher(Teacher $teacher): array;
}
