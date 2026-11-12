<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Jobs\SendPasswordResetLinkJob;
use App\Modules\Core\Support\PasswordResetTokens;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * «¿Olvidaste tu contraseña?» (AC-16): la pantalla pide el usuario (RFC o número de control), nunca el correo. Al
 * mandarla encola SendPasswordResetLinkJob con lo escrito, sin buscar al usuario, y responde siempre lo mismo: así no
 * delata, ni por la respuesta ni por el tiempo, si el usuario existe o puede restablecer. El límite de solicitudes por
 * usuario y por IP (AC-17) es el limitador `password-reset-link` (FortifyServiceProvider).
 */
final class ForgotPasswordPage
{
    public function show(PasswordResetTokens $tokens): View
    {
        return view('core::auth.forgot-password', ['minutes' => $tokens->minutes()]);
    }

    public function send(Request $request): RedirectResponse
    {
        $validated = $request->validate(['username' => ['required', 'string', 'max:255']]);

        SendPasswordResetLinkJob::dispatch($validated['username']);

        return redirect()->route('password.request')->with('status', 'password-reset-link-requested');
    }
}
