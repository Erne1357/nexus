<?php

namespace App\Modules\AcademicLoad\Policies;

use App\Modules\AcademicLoad\Exports\PublishedExport;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;

/**
 * Quién descarga un exporte de lo publicado (reglas-de-negocio.md, «Exportes de lo publicado»; decisión del usuario
 * del 10 de noviembre de 2026): lo público (PDF por carrera y semestre, por docente y por salón), cualquiera, también
 * sin sesión; lo demás (PDF por departamento, Excel del campus, archivos para el SII), quien tiene su permiso en la
 * modalidad (la de su campus) y la unidad del exporte. Registrada para la interfaz, así que vale para cada exporte que la implemente.
 */
final class PublishedExportPolicy
{
    use AuthorizesActor;

    public function download(?User $user, PublishedExport $export): bool
    {
        $permission = $export->permission();

        if ($permission === null) {
            return true;
        }

        return $user !== null && $this->actor($user)->allows($permission, $export->scope());
    }
}
