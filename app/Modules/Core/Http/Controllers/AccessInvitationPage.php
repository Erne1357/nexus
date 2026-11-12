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

/**
 * El enlace de la invitación de acceso (AC-08), para invitados. Abrirlo (GET) solo muestra la página con el botón
 * «Activar mi acceso», porque los filtros de correo abren los enlaces solos; el botón (POST al mismo enlace firmado)
 * llama a AcceptAccessInvitation, cierra la sesión que hubiera abierta en esa computadora, inicia la del invitado con
 * una sesión nueva y lo lleva directo a cambiar la contraseña, para que la contraseña por defecto dure lo menos posible. Un enlace con la firma rota o vencida, usado,
 * reenviado, de un correo que cambió o de un usuario que no existe o está inactivo muestra la misma página (403), sin
 * decir cuál de esas fue ni si el usuario existe.
 */
final class AccessInvitationPage
{
    public function show(Request $request, string $user, string $hash, AccessInvitationLink $link): Response
    {
        $invited = $request->hasValidSignature() ? KeyLookup::find(User::query(), $user) : null;

        if (! $invited instanceof User || ! $link->opens($invited, $hash)) {
            return $this->closed();
        }

        return response()->view('core::auth.accept-access', ['username' => $invited->username, 'action' => $request->fullUrl()]);
    }

    public function accept(Request $request, string $user, string $hash, AcceptAccessInvitation $acceptAccessInvitation): RedirectResponse|Response
    {
        $accepted = $request->hasValidSignature() ? $acceptAccessInvitation->handle($user, $hash) : null;

        if ($accepted === null) {
            return $this->closed();
        }

        $guard = Auth::guard(config('fortify.guard'));
        $guard->logout();
        $request->session()->invalidate();
        $guard->login($accepted);
        $request->session()->regenerate();

        return redirect()->route('user-password.edit');
    }

    private function closed(): Response
    {
        return response()->view('core::auth.accept-access', ['username' => null, 'action' => null], Response::HTTP_FORBIDDEN);
    }
}
