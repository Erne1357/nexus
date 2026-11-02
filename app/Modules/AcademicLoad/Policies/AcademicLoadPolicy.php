<?php

namespace App\Modules\AcademicLoad\Policies;

use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\UsesScopedContext;

/**
 * Quién ve y propone una carga, según docs/dominio/reglas-de-negocio.md, "Aprobaciones y permisos"
 * (filas "Ver la carga académica" y
 * "Proponer la carga (sale de borrador)"):
 *
 * - Ven la carga la subdirección, el jefe de departamento y el jefe de proyecto docente, dentro de su
 *   campus y su departamento.
 * - Propone (sale de borrador) el jefe de departamento de ese campus y departamento (supuesto por
 *   confirmar). covers() ya incluye al admin.
 *
 * Las demás abilities llegan con las transiciones de las fases 3 y 4.
 */
final class AcademicLoadPolicy
{
    use UsesScopedContext;

    public function view(User $user, AcademicLoad $load): bool
    {
        return $this->scope($user)->covers(
            [Role::Subdireccion, Role::JefeDepartamento, Role::JefeProyectoDocente],
            $load->campus_id,
            department: $load->department_id,
        );
    }

    public function propose(User $user, AcademicLoad $load): bool
    {
        return $this->scope($user)->covers(Role::JefeDepartamento, $load->campus_id, department: $load->department_id);
    }
}
