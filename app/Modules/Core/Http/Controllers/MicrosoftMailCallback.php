<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Actions\ConnectMailAccount;
use App\Modules\Core\Mail\MicrosoftGraphError;
use App\Modules\Core\Mail\MicrosoftSignIn;
use App\Modules\Core\Models\MailConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * El regreso del inicio de sesión de Microsoft al conectar la cuenta de envío (tarea 1.29, ADR-0032; Redirect URI de la
 * app de Azure). Solo el admin (MailConnectionPolicy::manage, CF-03). Valida el `state` contra la conexión que el mismo
 * usuario inició hace menos de 10 minutos (MicrosoftSignIn) y, con el código y el verificador de PKCE, conecta la cuenta
 * (ConnectMailAccount). Cualquier otro regreso no llama a Microsoft ni guarda nada. Siempre vuelve a «Correos y colas»
 * con un aviso para la tarjeta.
 */
final class MicrosoftMailCallback
{
    public function __invoke(Request $request, MicrosoftSignIn $signIn, ConnectMailAccount $connect): RedirectResponse
    {
        Gate::authorize('manage', MailConnection::class);

        $session = $request->session();
        $back = redirect()->route('core.mail-and-queues');
        $verifier = $signIn->finish($session, $request->query('state'), (string) $request->user()->getKey());
        $code = $request->query('code');

        if ($verifier === null) {
            MicrosoftSignIn::notify($session, 'error', 'No se conectó la cuenta de envío', 'El regreso de Microsoft no corresponde a una conexión que hayas iniciado aquí en los últimos 10 minutos. Vuelve a pulsar «Conectar con Microsoft».');

            return $back;
        }

        if (is_string($request->query('error')) || ! is_string($code) || $code === '') {
            $detail = $request->query('error_description') ?? $request->query('error');
            $why = is_string($detail) ? Str::limit(trim((string) strtok(trim($detail), "\r\n")), 300) : '';
            MicrosoftSignIn::notify($session, 'error', 'No se conectó la cuenta de envío', trim("Microsoft no dio el permiso. {$why}"));

            return $back;
        }

        try {
            $connection = $connect->handle($request->user(), $code, $verifier);
        } catch (MicrosoftGraphError $error) {
            MicrosoftSignIn::notify($session, 'error', 'No se conectó la cuenta de envío', $error->getMessage());

            return $back;
        }

        MicrosoftSignIn::notify($session, 'success', 'Cuenta de envío conectada', "El correo de Nexus sale como {$connection->account_email}. Manda un correo de prueba para revisarlo.");

        return $back;
    }
}
