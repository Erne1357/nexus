<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Actions\AcceptAccessInvitation;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Support\AccessInvitationLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * El enlace de la invitación de acceso (AC-08), para invitados. Abrirlo (GET) solo muestra el formulario «Activar mi
 * acceso» con la contraseña nueva y su confirmación, porque los filtros de correo abren los enlaces solos; la página no
 * manda el enlace como Referer a ningún lado. Mandarlo (POST al mismo enlace firmado) llama a AcceptAccessInvitation:
 * si la contraseña no pasa, regresa al formulario del mismo enlace con los errores, sin guardar en la sesión lo escrito;
 * si pasa, cierra la sesión que hubiera abierta en esa computadora, inicia la del invitado con una sesión nueva y lo
 * lleva a Inicio. Un enlace con la firma rota o vencida, usado, reenviado, de un correo que cambió o de un usuario que
 * no existe o está inactivo muestra la misma página (403), sin decir cuál de esas fue ni si el usuario existe, y sin
 * mirar lo escrito.
 */
final class AccessInvitationPage
{
    public function show(Request $request, string $user, string $hash, AccessInvitationLink $link): Response
    {
        $invited = $request->hasValidSignature() ? KeyLookup::find(User::query(), $user) : null;

        if (! $invited instanceof User || ! $link->opens($invited, $hash)) {
            return $this->closed();
        }

        return response()
            ->view('core::auth.accept-access', ['username' => $invited->username, 'action' => $request->fullUrl()])
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function accept(Request $request, string $user, string $hash, AcceptAccessInvitation $acceptAccessInvitation): RedirectResponse|Response
    {
        if (! $request->hasValidSignature()) {
            return $this->closed();
        }

        try {
            $accepted = $acceptAccessInvitation->handle($user, $hash, $request->only('password', 'password_confirmation'));
        } catch (ValidationException $exception) {
            return redirect()->to($request->fullUrl())->withErrors($exception->errors());
        }

        if ($accepted === null) {
            return $this->closed();
        }

        $guard = Auth::guard(config('fortify.guard'));
        $guard->logout();
        $request->session()->invalidate();
        $guard->login($accepted);
        $request->session()->regenerate();

        return redirect()->route('home');
    }

    private function closed(): Response
    {
        return response()->view('core::auth.accept-access', ['username' => null, 'action' => null], Response::HTTP_FORBIDDEN);
    }
}
