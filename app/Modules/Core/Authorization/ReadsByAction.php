<?php

namespace App\Modules\Core\Authorization;

/**
 * Permission::isReadOnly() por la acción de la clave (`modulo.recurso.accion`, AZ-03): solo lee si la acción empieza con
 * `view` (`view`, `view_all`, `view_department`…) o es `export` o `export_sii`. Un enum de permisos que tenga una
 * acción de lectura con otro nombre, o una que escriba aunque empiece con `view`, sobrescribe isReadOnly(); hoy ninguno
 * lo necesita (la lista la congela DirectivoTest).
 */
trait ReadsByAction
{
    public function isReadOnly(): bool
    {
        $action = substr((string) $this->value, (int) strrpos((string) $this->value, '.') + 1);

        return str_starts_with($action, 'view') || in_array($action, ['export', 'export_sii'], true);
    }
}
