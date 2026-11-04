<?php

namespace App\Modules\Students\Policies;

use App\Modules\Academic\Models\Career;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;
use App\Modules\Students\Enums\StudentsPermission;
use App\Modules\Students\Models\Student;

/**
 * Quién consulta alumnos, según reglas-de-negocio.md, "Aprobaciones y permisos" (fila "Consultar alumnos"):
 *
 * - El admin, a todos.
 * - La subdirección, a los de cualquier campus (su alcance es de todo el Tec).
 * - El jefe de departamento, a los de las carreras que coordina su departamento, en cualquier campus.
 * - El coordinador, a los de su carrera en su campus.
 *
 * Un alcance que todavía trae campus vale solo en ese campus (Actor).
 *
 * Se resuelve con el permiso `students.student.view` (StudentsPermission). Nadie más, ni el jefe de proyecto docente. Nadie los edita ni los borra: vienen del SII (no hay `update` ni
 * `delete`). El admin pasa por la concesión implícita del registro.
 */
final class StudentPolicy
{
    use AuthorizesActor;

    public function viewAny(User $user): bool
    {
        return $this->actor($user)->allowsAny(StudentsPermission::StudentView);
    }

    public function view(User $user, Student $student): bool
    {
        $department = $student->relationLoaded('career')
            ? $student->career?->department_id
            : Career::withTrashed()->whereKey($student->career_id)->value('department_id');

        return $this->actor($user)->allows(StudentsPermission::StudentView, Target::of(campus: $student->campus_id, unit: $department, career: $student->career_id));
    }
}
