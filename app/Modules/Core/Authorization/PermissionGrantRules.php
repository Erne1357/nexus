<?php

namespace App\Modules\Core\Authorization;

use App\Modules\Core\Enums\Role;

/**
 * Qué permiso se le puede dar a qué puesto en el panel (roles editables, CF-01), con el motivo en español de cada
 * bloqueo:
 *
 * - CF-S1: el admin no se edita; tiene todo lo declarado menos adminDenies(), en código.
 * - CF-03: un candado (adminOnly()) no se da a otro puesto, ni suelto ni dentro de un rol. Un rol puede llevar
 *   cualquier otro permiso: lo demás se revisa al darlo a un puesto.
 * - CF-04: un permiso de gestión solo se le da a un puesto que acota cada dimensión que el permiso declara
 *   (Role::boundedDimensions()); si no, valdría sin límite en esa dimensión. El docente y el alumno no acotan ninguna:
 *   solo reciben permisos personales. Las concesiones de fábrica del puesto (grants()) siempre valen, porque se
 *   revisaron por PR; scopeProblem() responde sin esa excepción.
 *
 * Sin estado: solo lee el registro, que es inmutable. GrantTable la usa también al armar su mapa, así que nada de lo
 * que bloquea vale aunque llegue a la base por otro camino.
 */
final class PermissionGrantRules
{
    public function __construct(private readonly PermissionRegistry $registry) {}

    /**
     * CF-S1: el motivo por el que el puesto no se edita en el panel, o null.
     */
    public function positionProblem(Role $role): ?string
    {
        return $role === Role::Admin
            ? 'El puesto Administración no se edita: tiene todo lo declarado menos sus excepciones.'
            : null;
    }

    /**
     * CF-03: el motivo por el que el permiso no puede ir en un rol ni darse suelto (es candado), o null.
     */
    public function lockProblem(Permission $permission): ?string
    {
        return $this->registry->isAdminOnly($permission)
            ? "«{$permission->label()}» es solo del admin: no se da a otro puesto."
            : null;
    }

    /**
     * CF-S1, CF-03 y CF-04: el motivo por el que `$permission` no se le puede dar a `$role`, o null si se puede. Lo que
     * el código le concede de fábrica al puesto siempre se puede.
     */
    public function grantProblem(Role $role, Permission $permission): ?string
    {
        return $this->positionProblem($role)
            ?? $this->lockProblem($permission)
            ?? ($this->registry->grants($role, $permission) ? null : $this->scopeProblem($role, $permission));
    }

    /**
     * CF-04 sin la excepción de fábrica: el motivo por el que dar `$permission` a `$role` ampliaría su alcance, o null.
     * Un permiso personal nunca lo amplía: vale solo sobre lo propio.
     */
    public function scopeProblem(Role $role, Permission $permission): ?string
    {
        if ($permission->isPersonal()) {
            return null;
        }

        $bounded = $role->boundedDimensions();

        if ($bounded === []) {
            return "«{$permission->label()}» no se da a {$role->listLabel()}: el puesto no lleva alcance y solo recibe permisos personales.";
        }

        foreach ($permission->dimensions() as $dimension) {
            if (! in_array($dimension, $bounded, true)) {
                [$noun, $all] = match ($dimension) {
                    Dimension::Campus => ['campus', 'todos los campus'],
                    Dimension::Unit => ['departamento', 'todos los departamentos'],
                    Dimension::Career => ['carrera', 'todas las carreras'],
                    Dimension::Modality => ['modalidad', 'todas las modalidades'],
                };

                return "«{$permission->label()}» no se da a {$role->listLabel()}: el puesto no lleva {$noun} y el permiso valdría en {$all}.";
            }
        }

        return null;
    }
}
