<?php

namespace App\Modules\Core\Enums;

/**
 * De dónde salió la contraseña de un usuario (`users.password_origin`, AC-12); nulo si no tiene (sin acceso, AC-01).
 *
 * - **Por defecto**: la contraseña por defecto, que puso el alta con acceso (CreateStaffUser), «Dar acceso» o el
 *   reinicio del admin antes de 1.22-C2; obliga a cambiarla. El panel ya no la pone: invita por enlace (CF-07).
 * - **NIP del SII**: la del alumno, que trae la migración del SII (AC-10). Solo con este origen el login completa con
 *   ceros un NIP corto (AC-11) y una nueva corrida la pone al día.
 * - **Propia**: la eligió el usuario al cambiarla (UpdateUserPassword), al restablecerla por correo
 *   (ResetForgottenPassword) o al activar su acceso con la invitación (AcceptAccessInvitation, AC-08); nadie la pisa.
 */
enum PasswordOrigin: string
{
    case Default = 'default';
    case SiiNip = 'sii_nip';
    case User = 'user';
}
