<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\UsesScopedContext;

/**
 * Quién administra periodos y su calendario, según reglas-de-negocio.md, "Aprobaciones y permisos"
 * (fila "Abrir periodo, calendario, perfil de campus": `admin`, `subdireccion`). Que la subdirección
 * dé de alta y edite periodos, los marque actuales y maneje el calendario es (supuesto por confirmar, docs/dominio/reglas-de-negocio.md, Periodo y calendario). Los periodos son de toda la
 * institución: no hay alcance por campus. Nadie los borra (supuesto por confirmar, mismo apartado): no hay `delete`.
 *
 * has() ya da por cubierto al admin.
 */
final class TermPolicy
{
    use UsesScopedContext;

    public function viewAny(User $user): bool
    {
        return $this->manages($user);
    }

    public function view(User $user, Term $term): bool
    {
        return $this->manages($user);
    }

    public function create(User $user): bool
    {
        return $this->manages($user);
    }

    public function update(User $user, Term $term): bool
    {
        return $this->manages($user);
    }

    /**
     * Marcar el periodo como el actual de la credencial.
     */
    public function makeCurrent(User $user, Term $term): bool
    {
        return $this->manages($user);
    }

    /**
     * Fijar y quitar las ventanas de las etapas.
     */
    public function manageCalendar(User $user, Term $term): bool
    {
        return $this->manages($user);
    }

    /**
     * Abrir el periodo en un campus o completar una apertura (fila "Abrir periodo, calendario, perfil de campus").
     */
    public function open(User $user, Term $term): bool
    {
        return $this->manages($user);
    }

    private function manages(User $user): bool
    {
        return $this->scope($user)->has(Role::Subdireccion);
    }
}
