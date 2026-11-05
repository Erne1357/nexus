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

    /**
     * A qué unidades aplica (D20): un nombramiento con unidad aporta el permiso solo si su unidad, o una de sus
     * descendientes, cumple (OrgTree::fits()). Nulo: a cualquiera, y entonces lo aporta todo nombramiento de un puesto
     * que lo tiene concedido. Un nombramiento sin unidad (admin, coordinador, docente) lo aporta siempre.
     */
    public function appliesTo(): ?UnitApplicability;
}
