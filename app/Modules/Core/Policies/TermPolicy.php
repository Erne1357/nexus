<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;

/**
 * Quién administra periodos y su calendario, según reglas-de-negocio.md, "Aprobaciones y permisos"
 * (fila "Abrir periodo, calendario, perfil de modalidad": `admin`, `subdireccion`). Que la subdirección
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
     * Abrir el periodo en una modalidad o completar una apertura (M4; fila "Abrir periodo, calendario, perfil de
     * campus": la Subdirección). El permiso declara la dimensión modalidad, así que se decide sobre el par (periodo,
     * modalidad). Nadie abre una modalidad inactiva (OpenTerm la rechaza): un nombramiento sin modalidad cubre solo las activas, y las inactivas solo se alcanzan para ver alumnos (M10). Sin modalidad pregunta si abre en alguna: es el `canOpen` de la página.
     */
    public function open(User $user, Term $term, ?Modality $modality = null): bool
    {
        return $this->actor($user)->allows(CorePermission::TermOpen, $modality === null ? null : Target::of(modality: $modality->getKey()));
    }
}
