<?php

namespace App\Modules\Staffing\Notifications;

use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\AllowsPersonalMail;
use App\Modules\Faculty\Models\Teacher;

/**
 * SEL-15 (confirmado el 24 de noviembre de 2026: «Sí, al personal si no hay institucional»): los avisos de la orden de
 * selección al docente de la cola (su hora al publicar, «en 10 minutos», el inicio de su turno, su hora de la ronda
 * extra y la cancelación) van a su correo institucional como los demás (SelectionNotice) y, si no tiene, al personal de
 * su ficha (`teachers.email`). Solo estos: el resto del correo de Nexus sigue CV11 (nunca el personal), también el aviso
 * de pendientes al jefe y al JPD (SelectionFinishedWithPending), que no hereda de esta clase. Es una de las excepciones
 * declaradas de CE-01 (AllowsPersonalMail, PersonalMailExceptions); también la usa «Confirma tus horas» (SEL-16,
 * PriorChoiceHoursPending).
 */
abstract class SelectionTeacherNotice extends SelectionNotice implements AllowsPersonalMail
{
    /**
     * SEL-15: estos avisos pueden caer al correo personal del docente.
     */
    public function personalMailRule(): ?string
    {
        return 'SEL-15';
    }

    public function mailAddressFor(User $user): ?string
    {
        if (! $user->is_active) {
            return null;
        }

        $institutional = parent::mailAddressFor($user);

        if ($institutional !== null) {
            return $institutional;
        }

        $personal = trim((string) Teacher::query()->where('user_id', $user->getKey())->value('email'));

        return $personal !== '' && filter_var($personal, FILTER_VALIDATE_EMAIL) !== false ? mb_strtolower($personal) : null;
    }
}
