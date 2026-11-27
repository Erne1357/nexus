<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\MailConnection;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\InstitutionalMail;
use App\Modules\Core\Notifications\MailAccountTest;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Manda el correo de prueba de la cuenta de envío (tarea 1.29, CF-S9) en ese momento y por `microsoft-graph`: al correo
 * de quien lo pide si es institucional (CE-01) y, si no, a la misma cuenta conectada (supuesto por confirmar). Es un diagnóstico, así que
 * sale sin cola para que el admin vea el resultado en el acto (una sola petición a Graph). Queda en el registro de
 * correos como cualquier otro (CF-S8) y, por el transporte, en la cuenta; no deja bitácora. Devuelve a dónde fue.
 *
 * @throws TransportExceptionInterface con el mensaje de Microsoft si no salió
 */
final class SendTestMail
{
    public function handle(User $actor, MailConnection $connection): string
    {
        $to = InstitutionalMail::isInstitutional($actor->email) ? mb_strtolower(trim((string) $actor->email)) : $connection->account_email;

        Notification::route('mail', $to)->notifyNow(new MailAccountTest($actor->name, $connection->account_email));

        return $to;
    }
}
