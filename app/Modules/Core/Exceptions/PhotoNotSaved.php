<?php

namespace App\Modules\Core\Exceptions;

use RuntimeException;

/**
 * Una foto que no se pudo escribir en el disco (CO-03, CO-06): permisos, disco lleno o el montaje sin escritura. A
 * diferencia de InvalidPhoto, la culpa no es de la imagen: quien la subió ve MESSAGE y el administrador revisa el disco.
 * Nada se registra ni se avisa como guardado.
 */
final class PhotoNotSaved extends RuntimeException
{
    public const string MESSAGE = 'No se pudo guardar la foto. Avisa al administrador.';

    public function __construct(string $detail = '')
    {
        parent::__construct($detail === '' ? self::MESSAGE : self::MESSAGE.' ('.$detail.')');
    }
}
