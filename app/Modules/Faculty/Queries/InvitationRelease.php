<?php

namespace App\Modules\Faculty\Queries;

use App\Modules\Faculty\Models\Teacher;

/**
 * Si la primera invitación de acceso de un docente ya se liberó: sale cuando un departamento suyo valida su catálogo
 * de docentes, y desde entonces la mandan su jefe o el admin (AC-07, AC-09). Faculty no conoce la validación de
 * catálogos, que está a su derecha en el mapa de módulos: liga NoInvitationRelease, y CatalogValidation lo reemplaza
 * en su register() con la regla de AC-09.
 */
interface InvitationRelease
{
    public function isReleased(Teacher $teacher): bool;
}
