<?php

namespace App\Modules\Core\Enums;

/**
 * De dónde salió la contraseña de un usuario (`users.password_origin`, AC-12); nulo si no tiene (sin acceso, AC-01).
 *
 * - **Por defecto**: la contraseña por defecto, que pone el alta manual del admin, «Dar acceso», el reinicio del admin
 *   o la invitación aceptada; obliga a cambiarla.
 * - **NIP del SII**: la del alumno, que trae la migración del SII (AC-10). Solo con este origen el login completa con
 *   ceros un NIP corto (AC-11) y una nueva corrida la pone al día.
 * - **Propia**: la eligió el usuario al cambiarla (UpdateUserPassword; también al restablecerla por correo, cuando
 *   exista); nadie la pisa.
 */
enum PasswordOrigin: string
{
    case Default = 'default';
    case SiiNip = 'sii_nip';
    case User = 'user';
}
