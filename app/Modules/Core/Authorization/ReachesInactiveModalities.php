<?php

namespace App\Modules\Core\Authorization;

/**
 * Marca de un permiso que alcanza también a las modalidades inactivas (M10). Un nombramiento sin modalidad cubre, por
 * omisión, solo las modalidades activas (MS8); con esta marca cubre además las inactivas no borradas. Es solo para lo
 * que se ve (los alumnos de una modalidad inactiva siguen siendo visibles): trabajar la carga de una modalidad inactiva
 * sigue bloqueado, así que ningún permiso de escritura la implementa. Un nombramiento que fija modalidad cuenta solo si
 * esa modalidad está activa, con o sin la marca.
 */
interface ReachesInactiveModalities
{
    public function reachesInactiveModalities(): bool;
}
