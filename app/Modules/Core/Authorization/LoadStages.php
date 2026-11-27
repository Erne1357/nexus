<?php

namespace App\Modules\Core\Authorization;

use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Models\Term;

/**
 * La carga que el usuario del contexto trabaja, para las pantallas por etapa del jefe y del JPD (MN-06): si ve la carga de
 * algún departamento y las etapas en que está la de su alcance en su periodo de carga (Actor::loadTerm(), u otro periodo si
 * se pide: el de un aviso que se abre). Con ellas AcademicLoadVisibility decide qué pantallas de la carga ven y abren. Core
 * no conoce las cargas, que están a su derecha en el mapa de módulos: liga NoLoadStages y AcademicLoad lo reemplaza en su
 * register().
 */
interface LoadStages
{
    /**
     * Si ve la carga de algún departamento con su perfil activo. El jefe de Servicios Escolares no la ve (AZ-02): no se rige
     * por la etapa.
     */
    public function worksLoad(Actor $context): bool;

    /**
     * @return list<Stage> sin repetir, en el orden del enum; vacía sin periodo de carga o sin cargas en su alcance
     */
    public function of(Actor $context, ?Term $term = null): array;
}
