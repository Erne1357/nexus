<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Actions\AcceptAccessInvitation;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Support\AccessInvitationLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * El enlace de la invitación de acceso (AC-08), para invitados. Abrirlo (GET) solo muestra la página con el botón
 * «Activar mi acceso», porque los filtros de correo abren los enlaces solos; el botón (POST al mismo enlace firmado)
 * llama a AcceptAccessInvitation y manda al login con un aviso. Un enlace con la firma rota o vencida, usado,
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
        if (! $request->hasValidSignature() || $acceptAccessInvitation->handle($user, $hash) === null) {
            return $this->closed();
        }

        return redirect()->route('login')->with('status', 'access-accepted');
    }

    private function closed(): Response
    {
        return response()->view('core::auth.accept-access', ['username' => null, 'action' => null], Response::HTTP_FORBIDDEN);
    }
}
