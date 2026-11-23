<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Http\Responses\PasswordUpdateResponse;
use App\Modules\Core\Queries\CredentialQuery;
use App\Modules\Core\Queries\PasswordReceiptQuery;
use App\Modules\Core\Support\PasswordRequirements;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Laravel\Fortify\Fortify;

/**
 * Cambiar la contraseña (tarea 1.30; DESIGN.md, "Pantallas de contraseña"). En el primer acceso
 * (`must_change_password`), la credencial cuelga en la escena del login; en un cambio voluntario,
 * de la banda de la aplicación. Recién guardada (`password-updated`), la credencial ya impresa
 * lleva colgado el acuse con el folio de la bitácora (PasswordReceiptQuery) y, si era el primer
 * acceso, el botón para entrar a la página que había pedido.
 */
final class ChangePasswordPage
{
    public function __invoke(Request $request, Actor $context, CredentialQuery $credential, PasswordReceiptQuery $receipts): View
    {
        $user = $request->user();
        $updated = $request->session()->get('status') === Fortify::PASSWORD_UPDATED;
        $continueTo = $request->session()->get(PasswordUpdateResponse::CONTINUE_TO);
        $previous = url()->previous();

        return view('core::auth.change-password', [
            'firstAccess' => (bool) $user->must_change_password,
            'credential' => $credential->of($context),
            'requirements' => PasswordRequirements::forChange(),
            'receipt' => $updated ? $receipts->latestFor($user) : null,
            'continueTo' => is_string($continueTo) ? $continueTo : null,
            'backTo' => $previous !== $request->url() ? $previous : route('home'),
        ]);
    }
}
