<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Actions\AcceptPrivacyNotice;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\CredentialQuery;
use App\Modules\Core\Rules\AccessRequirements;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * La pantalla «Aviso de privacidad» (AC-22, supuesto por confirmar; DESIGN.md, «Aviso de privacidad»): a ella manda
 * EnsurePrivacyNoticeAccepted al alumno que no ha aceptado la versión vigente. Con la escena del login, la credencial ya
 * impresa lleva el aviso simplificado en su recuadro, el enlace al integral, «Acepto» y «Salir» (cerrar sesión).
 * «Acepto» llama a AcceptPrivacyNotice y sigue a la página que había pedido. A quien no tiene que aceptarlo (el personal,
 * o el alumno que ya aceptó esta versión) la pantalla lo manda al inicio sin escribir nada.
 */
final class AcceptPrivacyNoticePage
{
    public function show(Request $request, AccessRequirements $requirements, Actor $context, CredentialQuery $credential): View|RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User || ! $requirements->mustAcceptPrivacyNotice($user)) {
            return redirect()->route('home');
        }

        return view('core::privacy-notice.accept', ['credential' => $credential->of($context)]);
    }

    public function accept(Request $request, AccessRequirements $requirements, AcceptPrivacyNotice $acceptPrivacyNotice): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User || ! $requirements->mustAcceptPrivacyNotice($user)) {
            return redirect()->route('home');
        }

        $acceptPrivacyNotice->handle($user);

        return redirect()->intended(route('home'));
    }
}
