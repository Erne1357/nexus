<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Actions\ResetForgottenPassword;
use App\Modules\Core\Support\PasswordResetTokens;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Timebox;

/**
 * El enlace para restablecer la contraseña (AC-17 y AC-18). Abrirlo (GET) solo muestra el formulario, porque los
 * filtros de correo abren los enlaces solos; guardarlo (POST, con el token y el usuario escondidos) llama a
 * ResetForgottenPassword y manda al login con un aviso. Un enlace vencido, usado, reemplazado, alterado o de un
 * usuario que no existe o ya no puede restablecer muestra la misma página (403), sin decir cuál de esas fue; esa
 * respuesta tarda al menos lo mismo que un login fallido (Timebox). La página del formulario no manda el enlace como
 * Referer a ningún lado.
 */
final class ResetPasswordPage
{
    /**
     * Microsegundos mínimos de una respuesta a un enlace que no sirve.
     */
    private const int CLOSED_LINK_MICROSECONDS = 200_000;

    public function show(Request $request, PasswordResetTokens $tokens): Response
    {
        $username = $request->string('username')->value();
        $token = $request->string('token')->value();

        return (new Timebox)->call(function (Timebox $timebox) use ($tokens, $username, $token): Response {
            if ($tokens->userFor($username, $token) === null) {
                return $this->closed();
            }

            $timebox->returnEarly();

            return response()
                ->view('core::auth.reset-password', ['username' => $username, 'token' => $token])
                ->header('Referrer-Policy', 'no-referrer');
        }, self::CLOSED_LINK_MICROSECONDS);
    }

    public function update(Request $request, ResetForgottenPassword $resetForgottenPassword): RedirectResponse|Response
    {
        $username = $request->string('username')->value();
        $token = $request->string('token')->value();
        $input = $request->only('password', 'password_confirmation');

        return (new Timebox)->call(function (Timebox $timebox) use ($resetForgottenPassword, $username, $token, $input): RedirectResponse|Response {
            if ($resetForgottenPassword->handle($username, $token, $input) === null) {
                return $this->closed();
            }

            $timebox->returnEarly();

            return redirect()->route('login')->with('status', 'password-reset');
        }, self::CLOSED_LINK_MICROSECONDS);
    }

    private function closed(): Response
    {
        return response()->view('core::auth.reset-password', ['username' => null, 'token' => null], Response::HTTP_FORBIDDEN);
    }
}
