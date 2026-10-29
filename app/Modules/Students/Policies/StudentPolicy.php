<?php

namespace App\Modules\Students\Policies;

use App\Modules\Academic\Models\Career;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\UsesScopedContext;
use App\Modules\Students\Models\Student;

/**
 * Quién consulta alumnos, según reglas-de-negocio.md, "Aprobaciones y permisos" (fila "Consultar alumnos"):
 *
 * - El admin, a todos.
 * - La subdirección, a los de su campus.
 * - El jefe de departamento, a los de las carreras que coordina su departamento, en su campus.
 * - El coordinador, a los de su carrera en su campus.
 *
 * Nadie más, ni el jefe de proyecto docente. Nadie los edita ni los borra: vienen del SII (no hay `update` ni
 * `delete`). covers() ya da por cubierto todo al admin, así que `view` no lo repite.
 */
final class StudentPolicy
{
    use UsesScopedContext;

    /** @var list<Role> */
    public const array VIEWERS = [Role::Subdireccion, Role::JefeDepartamento, Role::Coordinador];

    public function viewAny(User $user): bool
    {
        $scope = $this->scope($user);

        return array_any(self::VIEWERS, fn (Role $role): bool => $scope->has($role));
    }

    public function view(User $user, Student $student): bool
    {
        $department = $student->relationLoaded('career')
            ? $student->career?->department_id
            : Career::withTrashed()->whereKey($student->career_id)->value('department_id');

        return $this->scope($user)->covers(self::VIEWERS, $student->campus_id, department: $department, career: $student->career_id);
    }
}
