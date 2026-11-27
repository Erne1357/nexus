<?php

namespace App\Modules\Students\Http\Controllers;

use App\Modules\Students\Queries\CredentialVerificationQuery;
use Illuminate\Http\Response;

/**
 * La página pública de verificación de la credencial (AC-20, tarea 1.27): la abre el QR del reverso. Sin sesión, con
 * límite de peticiones (`throttle:credential-verify`), sin caché y sin indexar. Responde 200 aunque el código no sirva,
 * para no dar pistas, y solo muestra datos con un token vigente.
 */
final class VerifyCredential
{
    public function __invoke(string $token, CredentialVerificationQuery $verification): Response
    {
        $result = $verification->verify($token, now()->getTimestamp());

        return response()
            ->view('students::credential.verify', ['result' => $result, 'checkedAt' => now('America/Ciudad_Juarez')])
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
