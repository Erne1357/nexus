<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use Carbon\CarbonInterface;

/**
 * Qué docentes tienen un puesto administrativo (tarea 1.54, SEL-07; decisión del usuario, confirmado el 26 de noviembre
 * de 2026: «Se le tiene que dar prioridad a los docentes que tienen o ayudan con una carga administrativa»): un
 * nombramiento vigente ese día en un puesto de `Role::ADMINISTRATIVE` (jefe de departamento, jefe de proyecto docente,
 * coordinador, subdirector, directivo, personal de área) del usuario del docente, o una plaza vigente de tipo `apoyo`
 * (supuesto por confirmar). Dos consultas, sin importar cuántos docentes.
 */
final class AdministrativePostsQuery
{
    /**
     * El puesto de cada docente administrativo, por id de docente: los nombres de sus puestos y plazas de apoyo, juntos.
     * Los que no tienen no aparecen.
     *
     * @param  list<string>  $teacherIds
     * @return array<string, string>
     */
    public function forTeachers(array $teacherIds, CarbonInterface $on): array
    {
        if ($teacherIds === []) {
            return [];
        }

        $day = $on->toImmutable()->setTimezone(config('app.timezone'))->toDateString();
        $users = Teacher::query()->whereIn('id', $teacherIds)->whereNotNull('user_id')->pluck('user_id', 'id')->all();
        $posts = [];

        RoleAssignment::query()
            ->whereIn('user_id', array_values($users))
            ->whereIn('role', array_map(fn (Role $role): string => $role->value, Role::ADMINISTRATIVE))
            ->where('valid_from', '<=', $day)
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $day))
            ->get(['user_id', 'role'])
            ->each(function (RoleAssignment $assignment) use (&$posts, $users): void {
                foreach (array_keys($users, $assignment->user_id, true) as $teacherId) {
                    $posts[$teacherId][] = $assignment->role->label();
                }
            });

        TeacherPosition::query()
            ->currentOn($on)
            ->whereIn('teacher_id', $teacherIds)
            ->where('kind', PositionKind::Apoyo->value)
            ->pluck('teacher_id')
            ->each(function (string $teacherId) use (&$posts): void {
                $posts[$teacherId][] = PositionKind::Apoyo->label();
            });

        return array_map(fn (array $labels): string => implode(', ', array_values(array_unique($labels))), $posts);
    }
}
