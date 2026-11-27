<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\PasswordOrigin;
use App\Modules\Core\Http\Responses\PasswordUpdateResponse;
use App\Modules\Core\Queries\CredentialQuery;
use App\Modules\Core\Queries\PasswordReceiptQuery;
use App\Modules\Core\Rules\AccessRequirements;
use App\Modules\Core\Support\PasswordRequirements;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Laravel\Fortify\Fortify;

/**
 * Cambiar la contraseña (tarea 1.30; DESIGN.md, "Pantallas de contraseña"). En el primer acceso
 * (`must_change_password`) y en el cambio obligatorio del NIP del SII (AC-12), la credencial cuelga en la escena del
 * login; en un cambio voluntario, de la banda de la aplicación. Si es alumno y no ha aceptado el aviso de privacidad
 * vigente, el formulario lleva el aviso simplificado y la casilla para aceptarlo (AC-22). Recién guardada
 * (`password-updated`), la credencial ya impresa lleva colgado el acuse con el folio de la bitácora
 * (PasswordReceiptQuery) y, si era el primer acceso, el botón para entrar a la página que había pedido.
 */
final class ChangePasswordPage
{
    public function __invoke(Request $request, Actor $context, CredentialQuery $credential, PasswordReceiptQuery $receipts, AccessRequirements $requirements): View
    {
        $user = $request->user();
        $updated = $request->session()->get('status') === Fortify::PASSWORD_UPDATED;
        $continueTo = $request->session()->get(PasswordUpdateResponse::CONTINUE_TO);

        return view('core::auth.change-password', [
            'firstAccess' => AccessRequirements::mustChangePassword($user),
            'fromSiiNip' => $user->password_origin === PasswordOrigin::SiiNip,
            'privacyNotice' => $requirements->mustAcceptPrivacyNotice($user),
            'credential' => $credential->of($context),
            'requirements' => PasswordRequirements::forChange(),
            'receipt' => $updated ? $receipts->latestFor($user) : null,
            'continueTo' => is_string($continueTo) ? $continueTo : null,
            'backTo' => $this->backTo($request, url()->previous()),
        ]);
    }

    /**
     * A dónde lleva «Volver sin cambiarla»: la página anterior si es de Nexus y no es esta misma; si no (sin anterior, un
     * Referer de otro sitio o esta página), el inicio. url()->previous() prefiere el Referer, que manda el navegador.
     */
    private function backTo(Request $request, string $previous): string
    {
        $root = rtrim($request->root(), '/').'/';
        $own = $previous === rtrim($root, '/') || str_starts_with($previous, $root);

        return $own && $previous !== $request->url() ? $previous : route('home');
    }
}
