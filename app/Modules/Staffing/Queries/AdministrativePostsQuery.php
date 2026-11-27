<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Core\Authorization\AdministrativeAppointments;
use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use Carbon\CarbonInterface;

/**
 * Qué docentes tienen un puesto administrativo (tarea 1.54, SEL-07; decisión del usuario, confirmado el 26 de noviembre
 * de 2026: «Se le tiene que dar prioridad a los docentes que tienen o ayudan con una carga administrativa»): un
 * nombramiento vigente ese día en un puesto de los puestos administrativos de Core (AdministrativeAppointments: jefe de departamento, jefe de proyecto docente,
 * coordinador, subdirector, directivo, personal de área) del usuario del docente, o una plaza vigente de tipo `apoyo`
 * (supuesto por confirmar). Cuenta cualquier puesto administrativo vigente, de cualquier departamento o campus (el docente «tiene
 * o ayuda con» carga administrativa); `valid_from` nulo es sin inicio, como TeacherPosition::currentOn. Dos consultas, sin importar cuántos docentes.
 */
final class AdministrativePostsQuery
{
    public function __construct(private readonly AdministrativeAppointments $appointments) {}

    /** El motivo de quien pasa primero por una plaza de apoyo. */
    public const string SUPPORT = 'Plaza de apoyo';

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

        $users = Teacher::query()->whereIn('id', $teacherIds)->whereNotNull('user_id')->pluck('user_id', 'id')->all();
        $posts = [];

        foreach ($this->appointments->titlesOf(array_values($users), $on) as $userId => $titles) {
            foreach (array_keys($users, $userId, true) as $teacherId) {
                $posts[$teacherId] = [...($posts[$teacherId] ?? []), ...$titles];
            }
        }

        TeacherPosition::query()
            ->currentOn($on)
            ->whereIn('teacher_id', $teacherIds)
            ->where('kind', PositionKind::Apoyo->value)
            ->pluck('teacher_id')
            ->each(function (string $teacherId) use (&$posts): void {
                $posts[$teacherId][] = self::SUPPORT;
            });

        return array_map(fn (array $labels): string => implode(', ', array_values(array_unique($labels))), $posts);
    }
}
