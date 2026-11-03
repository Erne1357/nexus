<?php

namespace App\Modules\Core\Enums;

/**
 * Qué abre un atajo de la pantalla de acceso (LG-02): un enlace a otra página o una descarga de un
 * archivo del disco público.
 */
enum LoginShortcutKind: string
{
    case Link = 'enlace';
    case Download = 'descarga';
}
