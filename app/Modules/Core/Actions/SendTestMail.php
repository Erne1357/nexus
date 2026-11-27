<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\MailConnection;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\InstitutionalMail;
use App\Modules\Core\Notifications\MailAccountTest;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Manda el correo de prueba de la cuenta de envío (tarea 1.29, CF-S9) en ese momento y por `microsoft-graph`: al correo
 * de quien lo pide si es institucional (CE-01) y, si no, a la misma cuenta conectada (supuesto por confirmar). Es un diagnóstico, así que
 * sale sin cola para que el admin vea el resultado en el acto (una sola petición a Graph). Queda en el registro de
 * correos como cualquier otro (CF-S8) y, por el transporte, en la cuenta; no deja bitácora. Devuelve a dónde fue. Si
 * ninguno de los dos es institucional no lo manda (la guardia de CE-01 lo bloquearía) y lo dice.
 *
 * @throws TransportExceptionInterface con el mensaje de Microsoft si no salió
 * @throws ValidationException sobre `test_mail` si no hay un correo institucional al que mandarlo
 */
final class SendTestMail
{
    public const string NO_INSTITUTIONAL = 'Ni tu correo ni la cuenta de envío son institucionales, así que Nexus no lo puede mandar (CE-01). Captura tu correo institucional en tu cuenta.';

    public function handle(User $actor, MailConnection $connection): string
    {
        $to = InstitutionalMail::isInstitutional($actor->email) ? mb_strtolower(trim((string) $actor->email)) : $connection->account_email;

        if (! InstitutionalMail::isInstitutional($to)) {
            throw ValidationException::withMessages(['test_mail' => self::NO_INSTITUTIONAL]);
        }

        Notification::route('mail', $to)->notifyNow(new MailAccountTest($actor->name, $connection->account_email));

        return $to;
    }
}
