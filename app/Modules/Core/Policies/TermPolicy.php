<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;

/**
 * Quién administra periodos y su calendario, según reglas-de-negocio.md, "Aprobaciones y permisos"
 * (fila "Abrir periodo, calendario, perfil de campus": `admin`, `subdireccion`). Que la subdirección
 * dé de alta y edite periodos, los marque actuales y maneje el calendario es (supuesto por confirmar, docs/dominio/reglas-de-negocio.md, Periodo y calendario). Los periodos son de toda la
 * institución: no hay alcance por campus. Nadie los borra (supuesto por confirmar, mismo apartado): no hay `delete`.
 *
 * Cada ability se resuelve con un permiso `core.term.*` (CorePermission); el admin los tiene todos.
 */
final class TermPolicy
{
    use AuthorizesActor;

    public function viewAny(User $user): bool
    {
        return $this->actor($user)->allowsAny(CorePermission::TermView);
    }

    public function view(User $user, Term $term): bool
    {
        return $this->actor($user)->allows(CorePermission::TermView);
    }

    public function create(User $user): bool
    {
        return $this->actor($user)->allows(CorePermission::TermCreate);
    }

    public function update(User $user, Term $term): bool
    {
        return $this->actor($user)->allows(CorePermission::TermUpdate);
    }

    /**
     * Marcar el periodo como el actual de la credencial.
     */
    public function makeCurrent(User $user, Term $term): bool
    {
        return $this->actor($user)->allows(CorePermission::TermMakeCurrent);
    }

    /**
     * Fijar y quitar las ventanas de las etapas.
     */
    public function manageCalendar(User $user, Term $term): bool
    {
        return $this->actor($user)->allows(CorePermission::TermManageCalendar);
    }

    /**
     * Abrir el periodo en un campus o completar una apertura (fila "Abrir periodo, calendario, perfil de campus").
     */
    public function open(User $user, Term $term): bool
    {
        return $this->actor($user)->allows(CorePermission::TermOpen);
    }
}
