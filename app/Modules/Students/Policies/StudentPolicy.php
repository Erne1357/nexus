<?php

namespace App\Modules\Students\Policies;

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\UsesScopedContext;
use App\Modules\Students\Models\Student;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        $student->loadMissing(['career' => fn (BelongsTo $career) => $career->withTrashed()]);

        return $this->scope($user)->covers(self::VIEWERS, $student->campus_id, department: $student->career->department_id, career: $student->career_id);
    }
}
