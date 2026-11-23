<?php

namespace App\Modules\Core\Support;

/**
 * Los requisitos de una contraseña nueva como se le muestran a la persona en las pantallas de contraseña (tarea 1.30;
 * DESIGN.md, "Pantallas de contraseña"), con la llave que revisa en el navegador `nexusPassword` (resources/js/app.js).
 * Son los mismos que valida el servidor: Password::defaults() (al menos 8 caracteres, con letras y números;
 * FortifyServiceProvider), `different:current_password` al cambiarla (UpdateUserPassword) y la confirmación. La
 * contraseña por omisión (DefaultPassword) también se rechaza, pero solo en el servidor: no se le muestra a nadie.
 */
final class PasswordRequirements
{
    /**
     * @var array<string, string>
     */
    private const array LABELS = [
        'length' => 'Al menos 8 caracteres',
        'letter' => 'Una letra',
        'number' => 'Un número',
        'different' => 'Distinta de la actual',
        'match' => 'Las dos coinciden',
    ];

    /**
     * Al cambiarla con la actual (primer acceso o cambio voluntario).
     *
     * @return array<string, string>
     */
    public static function forChange(): array
    {
        return self::LABELS;
    }

    /**
     * Al elegirla sin la actual (el enlace del correo).
     *
     * @return array<string, string>
     */
    public static function forReset(): array
    {
        return array_diff_key(self::LABELS, ['different' => true]);
    }
}
