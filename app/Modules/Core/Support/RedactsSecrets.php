<?php

namespace App\Modules\Core\Support;

/**
 * Oculta lo que parece un secreto en un texto que se guarda o se muestra (errores de correos y de trabajos fallidos, CF-S8):
 * `token=` y `signature=` en una URL, aunque lleven prefijo (`reset_token=`, `access_token=`); esas claves dentro de un
 * JSON (`"token":"…"`); el token de la ruta de restablecer contraseña (`/reset-password/{token}`) y la huella de la ruta
 * de la invitación de acceso (`/access/{user}/{hash}`). La bitácora y el registro de correos no se editan, así que
 * esto va antes de escribir.
 */
final class RedactsSecrets
{
    public const string MASK = '[oculto]';

    public static function redact(string $text): string
    {
        $mask = self::MASK;

        return (string) preg_replace(
            [
                '/([A-Za-z0-9_]*(?:token|signature)=)[^&\s"\')\]]+/i',
                '/(\\\\?"[A-Za-z0-9_]*(?:token|signature)\\\\?"\s*:\s*\\\\?")[^"\\\\]*/i',
                '#(/reset-password/)[^/\s?"\')\]]+#i',
                '#(/access/[^/\s?"\')\]]+/)[^/\s?"\')\]]+#i',
            ],
            ['$1'.$mask, '$1'.$mask, '$1'.$mask, '$1'.$mask],
            $text,
        );
    }
}
