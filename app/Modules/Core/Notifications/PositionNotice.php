<?php

namespace App\Modules\Core\Notifications;

/**
 * Un aviso que compete al puesto y no a la persona (CE-02, confirmado el 26 de noviembre de 2026): su correo va al
 * buzón institucional del puesto (`position_mailboxes`), nunca al de quien lo ocupa. position() da el puesto (el rol
 * con que se mandó, `addressedRole()`, y el alcance que trae el aviso) o null si esta instancia no es de un puesto (no
 * se mandó a un puesto, o es un aviso en cola de antes que no trae el alcance): entonces sigue a la persona. Sin buzón,
 * la campana se guarda y el correo espera (HoldPositionMail). Lo implementan las notificaciones de los módulos; Core no
 * las conoce.
 */
interface PositionNotice
{
    public function position(): ?PositionRef;
}
