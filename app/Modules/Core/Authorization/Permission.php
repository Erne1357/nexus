<?php

namespace App\Modules\Core\Authorization;

/**
 * Contrato del catálogo de permisos: cada módulo declara un enum respaldado por string que lo
 * implementa. El valor es la clave `modulo.recurso.accion`, la única que el código y la bitácora
 * usan para nombrar el permiso.
 */
interface Permission extends \BackedEnum
{
    /**
     * Nombre para la interfaz.
     */
    public function label(): string;

    /**
     * Dimensiones del alcance en las que el permiso se evalúa; vacío si no depende de ninguna.
     *
     * @return list<Dimension>
     */
    public function dimensions(): array;

    /**
     * Si el permiso es sobre lo propio (el dueño del registro es el usuario) y no sobre un alcance.
     */
    public function isPersonal(): bool;
}
